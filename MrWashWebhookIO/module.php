<?php declare(strict_types=1);

include_once __DIR__ . '/../libs/WebHookModule.php';

class MrWashWebhookIO extends WebHookModule
{
    // DataID used between IO -> Splitter (must match module.json implemented/requirements)
    private const IFACE_TO_SPLITTER = '{F4B171C0-030A-4B6A-9415-8BBF6949380D}';

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID, 'MrWash/' . $InstanceID);
    }

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('EnableDebug', true);
        $this->RegisterPropertyString('Token', $this->GenerateToken());
        $this->RegisterPropertyString('AllowedDevice', '');
    }

    private function EnsurePropertyDefinitions(): void
    {
        // Migration helper: suppress warnings if already registered
        @$this->RegisterPropertyBoolean('EnableDebug', true);
        @$this->RegisterPropertyString('Token', $this->GenerateToken());
        @$this->RegisterPropertyString('AllowedDevice', '');
    }

    public function ApplyChanges(): void
    {
        $this->EnsurePropertyDefinitions();
        parent::ApplyChanges();
    }

public function GetConfigurationForm(): string
    {
        $form = json_decode((string)file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            return json_encode(['elements' => [], 'actions' => []]);
        }

        $token = (string)$this->ReadPropertyString('Token');
        $hook  = '/hook/MrWash/' . $this->InstanceID;

        // Best-effort base URL (available when the form is requested via WebFront/Console).
        $base = '';
        if (!empty($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . $_SERVER['HTTP_HOST'];
        }

        $relative = $hook . '?token=' . rawurlencode($token);
        $url      = ($base !== '' ? $base : '') . $relative;

        $urlJson = $url . '&action=export&format=json';
        $urlCsv  = $url . '&action=export&format=csv';

        // Inject dynamic values into the form (fields have no "name", so they are display-only but copyable).
        if (isset($form['elements']) && is_array($form['elements'])) {
            foreach ($form['elements'] as &$el) {
                if (!is_array($el) || ($el['type'] ?? '') !== 'ValidationTextBox') {
                    continue;
                }
                $caption = (string)($el['caption'] ?? '');
                switch ($caption) {
                    case 'Webhook URL':
                        $el['value'] = $url;
                        break;
                    case 'Export URL JSON':
                        $el['value'] = $urlJson;
                        break;
                    case 'Export URL CSV':
                        $el['value'] = $urlCsv;
                        break;
                }
            }
            unset($el);
        }

        return json_encode($form);
    }

    public function RegenerateToken(): void
    {
        IPS_SetProperty($this->InstanceID, 'Token', $this->GenerateToken());
        IPS_ApplyChanges($this->InstanceID);
    }

    // Called by WebHookModule via WebHook Control
    protected function ProcessHookData()
    {
        header('Content-Type: text/plain; charset=utf-8');

        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
        $uri    = (string)($_SERVER['REQUEST_URI'] ?? '');
        $uri    = preg_replace('/([\?&]token=)[^&]+/i', '$1***', $uri);
        $this->Dbg('Hook', trim($method . ' ' . $uri . ' from ' . $remote));

        // --- Auth ---
        $token    = (string)($_GET['token'] ?? $_POST['token'] ?? ($_SERVER['HTTP_X_TOKEN'] ?? ''));
        $expected = (string)$this->ReadPropertyString('Token');
        if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
            $this->Dbg('Auth', 'Forbidden (token mismatch)');
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        $this->Dbg('Auth', 'Token OK');

        // --- Export (proxy to the only connected device) ---
        $action = strtolower((string)($_GET['action'] ?? $_POST['action'] ?? ''));
        if ($action === 'export') {
            $format = strtolower((string)($_GET['format'] ?? $_POST['format'] ?? 'json'));
            $limit  = (int)($_GET['limit'] ?? $_POST['limit'] ?? 0);
            $target = (int)($_GET['target'] ?? $_POST['target'] ?? 0);

            $deviceId = $this->ResolveSingleDevice($target);
            $this->Dbg('Export', 'target=' . $target . ' resolvedDevice=' . $deviceId . ' format=' . $format . ' limit=' . $limit);

            if ($deviceId <= 0) {
                http_response_code(503);
                echo 'No device connected (or multiple devices, set ?target=InstanceID)';
                return;
            }

            $json = MRWASH_GetVisits($deviceId, $limit);

            if ($format === 'csv') {
                $arr = json_decode($json, true);
                if (!is_array($arr)) {
                    $arr = [];
                }
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="mrwash_visits.csv"');
                $out = fopen('php://output', 'w');
                fputcsv($out, ['exit', 'entry', 'duration_min', 'program', 'location', 'device', 'singleEvent']);
                foreach ($arr as $v) {
                    $exit = (int)($v['exit'] ?? 0);
                    $entry = (int)($v['entry'] ?? 0);
                    $duration = (int)($v['durationMin'] ?? 0);
                    $program = (string)($v['program'] ?? '');
                    $location = (string)($v['location'] ?? '');
                    $dev = (string)($v['device'] ?? '');
                    $single = (int)($v['singleEvent'] ?? 0);
                    fputcsv($out, [$exit, $entry, $duration, $program, $location, $dev, $single]);
                }
                fclose($out);
                return;
            }

            header('Content-Type: application/json; charset=utf-8');
            echo $json;
            return;
        }

        // --- Normal event ---
        $payload = $this->ReadPayload();
        if ($payload === null) {
            http_response_code(400);
            echo 'Bad Request';
            return;
        }
        $this->Dbg('Payload', json_encode($payload, JSON_UNESCAPED_UNICODE));

        

        // Normalize timestamp: use payload['date'] if present (Geofency sends ISO 8601), fallback to server time only if missing/invalid.
        $ts = $this->ParseTimestamp($payload['date'] ?? ($payload['Date'] ?? null));
        if ($ts === null) {
            $this->Dbg('Time', 'No/invalid date in payload -> using server time()');
            $ts = time();
        } else {
            $this->Dbg('Time', 'Using payload date -> ts=' . $ts . ' (' . date('c', $ts) . ')');
        }
        $payload['_timestamp'] = $ts;
// Optional device filter
        $device = trim((string)($payload['device'] ?? ''));
        $allowed = trim((string)$this->ReadPropertyString('AllowedDevice'));
        if ($allowed !== '' && strcasecmp($allowed, $device) !== 0) {
            $this->Dbg('Filter', 'Ignored device=' . $device . ' allowed=' . $allowed);
            http_response_code(202);
            echo 'Ignored';
            return;
        }

        $this->Dbg('Forward', 'Sending to children (DataID ' . self::IFACE_TO_SPLITTER . ')');
        $this->SendDataToChildren(json_encode([
            'DataID' => self::IFACE_TO_SPLITTER,
            'Buffer' => json_encode($payload, JSON_UNESCAPED_UNICODE)
        ]));

        http_response_code(200);
        echo 'OK';
    }

    private function ReadPayload(): ?array
    {
        if (!empty($_POST) && is_array($_POST)) {
            return $_POST;
        }

        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            parse_str($raw, $parsed);
            if (is_array($parsed) && !empty($parsed)) {
                return $parsed;
            }
        }

        // As last resort: GET params without control keys
        $tmp = $_GET;
        unset($tmp['token'], $tmp['action'], $tmp['format'], $tmp['limit'], $tmp['target']);
        return (!empty($tmp) ? $tmp : null);
    }

    private function GenerateToken(int $length = 32): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $token;
    }

    private function ResolveSingleDevice(int $preferred): int
    {
        // Find splitters connected to this IO
        $splitters = [];
        foreach (IPS_GetInstanceList() as $iid) {
            $inst = IPS_GetInstance($iid);
            if ((int)$inst['ConnectionID'] === $this->InstanceID) {
                $splitters[] = $iid;
            }
        }

        // Find devices connected to splitters
        $devices = [];
        foreach ($splitters as $sid) {
            foreach (IPS_GetInstanceList() as $iid) {
                $inst = IPS_GetInstance($iid);
                if ((int)$inst['ConnectionID'] === (int)$sid) {
                    $devices[] = $iid;
                }
            }
        }

        $devices = array_values(array_unique($devices));
        if ($preferred > 0 && in_array($preferred, $devices, true)) {
            return $preferred;
        }

        return (count($devices) === 1) ? $devices[0] : 0;
    }

    private function IsDebugEnabled(): bool
    {
        $cfg = json_decode((string)@IPS_GetConfiguration($this->InstanceID), true);
        if (!is_array($cfg) || !array_key_exists('EnableDebug', $cfg)) {
            return true; // default
        }
        return (bool)$cfg['EnableDebug'];
    }

    private function Dbg(string $topic, $data): void
    {
        if (!$this->IsDebugEnabled()) {
            return;
        }
        if (is_array($data) || is_object($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        $this->SendDebug($topic, (string)$data, 0);
    }

    private function ParseTimestamp($value): ?int
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string)$value);
        if ($v === '') {
            return null;
        }

        // Workaround: With application/x-www-form-urlencoded, a '+' in the timezone offset may arrive as a space.
        // Example: 2025-11-17T10:00:00+01:00 -> "2025-11-17T10:00:00 01:00"
        if (preg_match('/T\d{2}:\d{2}:\d{2} \d{2}:\d{2}$/', $v) === 1) {
            $v = preg_replace('/ (\d{2}:\d{2})$/', '+$1', $v);
        }
        if (preg_match('/T\d{2}:\d{2}:\d{2} \d{4}$/', $v) === 1) {
            $v = preg_replace('/ (\d{4})$/', '+$1', $v);
        }

        // Unix timestamp?
        if (ctype_digit($v)) {
            $ts = (int)$v;
            // Reasonable epoch guard
            if ($ts > 1000000000) {
                return $ts;
            }
        }

        $ts = strtotime($v);
        return ($ts !== false) ? $ts : null;
    }

}
