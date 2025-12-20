<?php declare(strict_types=1);

class MrWashFlatrate extends IPSModule
{
    private const PROFILE_PROGRAM = 'MRWASH.Program';
    private const SEMAPHORE = 'MRWASH_';

    // Term codes (match form options)
    private const TERM_MONTHLY = 0;
    private const TERM_YEARLY = 1;
    private const TERM_CUSTOM_DAYS = 2;
    private const TERM_FLATWASH_3M = 3;  // 93 days
    private const TERM_FLATWASH_12M = 4; // 372 days

    public function Create()
    {
        parent::Create();



        $this->RegisterPropertyBoolean('EnableDebug', true);
        // Ensure variable profiles exist before registering variables (avoids warnings on instance creation)
        $this->MaintainProgramProfile();
        // --- Properties ---
        $this->RegisterPropertyInteger('Plan', 1);
        $this->RegisterPropertyFloat('SinglePriceExterior', 0.0);
        $this->RegisterPropertyFloat('SinglePriceInterior', 0.0);

        $this->RegisterPropertyString('StartDate', date('Y-m-d'));
        $this->RegisterPropertyInteger('BillingCycle', self::TERM_MONTHLY);
        $this->RegisterPropertyInteger('BillingCycleDays', 30);

        $this->RegisterPropertyFloat('SubscriptionPrice', 0.0);
        $this->RegisterPropertyFloat('SubscriptionPrice3M', 0.0);
        $this->RegisterPropertyFloat('SubscriptionPrice12M', 0.0);

        $this->RegisterPropertyInteger('BreakEvenAssumption', 0);

        $this->RegisterPropertyBoolean('RenewalBonusEnabled', true);
        $this->RegisterPropertyString('Renewals', json_encode([]));

        $this->RegisterPropertyInteger('InteriorThresholdMinutes', 15);
$this->RegisterPropertyInteger('HistoryMax', 200);
        $this->RegisterPropertyBoolean('SingleEventMode', false);

        $this->RegisterPropertyBoolean('EnableFairUseWarnings', true);
        $this->RegisterPropertyFloat('FairUseMaxPerWeek', 3.0);
        $this->RegisterPropertyFloat('FairUseMinAvgIntervalDays', 2.0);
        $this->RegisterPropertyInteger('FairUseMaxUses3M', 50);
        $this->RegisterPropertyInteger('FairUseMaxUses12M', 200);

        // --- Attributes (persistent state) ---
        $this->RegisterAttributeString('Visits', json_encode([]));
        $this->RegisterAttributeString('OpenEntries', json_encode(new stdClass()));

        // --- Timer ---
        $this->RegisterTimer('UpdateMetrics', 0, 'MRWASH_UpdateMetrics($_IPS[\'TARGET\']);');

        // --- Variables ---
        $this->RegisterVariableString('Dashboard', $this->Translate('Dashboard'), '~HTMLBox', 0);

        $this->RegisterVariableInteger('VisitsThisPeriod', 'Wäschen (akt. Zeitraum)', '', 10);
        $this->RegisterVariableInteger('VisitsExteriorThisPeriod', 'Außenwäschen (akt. Zeitraum)', '', 11);
        $this->RegisterVariableInteger('VisitsInteriorThisPeriod', 'Innenraum (akt. Zeitraum)', '', 12);

        $this->RegisterVariableFloat('EquivalentCostThisPeriod', 'Kosten ohne Abo (akt. Zeitraum)', '~Euro', 20);
        $this->RegisterVariableFloat('SubscriptionCostThisPeriod', 'Abo-Kosten (akt. Zeitraum)', '~Euro', 21);
        $this->RegisterVariableFloat('SavingsThisPeriod', 'Ersparnis (akt. Zeitraum)', '~Euro', 22);

        $this->RegisterVariableInteger('AdditionalWashesToBreakEven', 'Noch nötige Wäschen bis Break-Even', '', 30);
        $this->RegisterVariableFloat('RecommendedIntervalDays', 'Empf. Intervall (Tage)', '', 31);

        $this->RegisterVariableInteger('ContractStart', 'Zeitraum Start', '~UnixTimestamp', 34);
        $this->RegisterVariableInteger('ContractEnd', 'Zeitraum Ende', '~UnixTimestamp', 35);

        $this->RegisterVariableInteger('LastVisit', 'Letzter Besuch', '~UnixTimestamp', 40);
        $this->RegisterVariableInteger('LastProgram', 'Letztes Programm', self::PROFILE_PROGRAM, 41);
        $this->RegisterVariableInteger('LastDurationMinutes', 'Letzte Dauer (Min)', '', 42);
        $this->RegisterVariableString('LastLocation', 'Letzte Location', '', 43);

        // Totals since start
        
        // Archive variable: complete visit history as JSON (for external analysis / manual edits)
        $this->RegisterVariableString('HistoryJSON', 'History (JSON)', '', 105);
        $this->EnableAction('HistoryJSON');

$this->RegisterVariableInteger('VisitsTotal', 'Wäschen (seit Start)', '', 110);
        $this->RegisterVariableInteger('VisitsExteriorTotal', 'Außenwäschen (seit Start)', '', 111);
        $this->RegisterVariableInteger('VisitsInteriorTotal', 'Innenraum (seit Start)', '', 112);

        $this->RegisterVariableFloat('EquivalentCostTotal', 'Kosten ohne Abo (seit Start)', '~Euro', 120);
        $this->RegisterVariableFloat('SubscriptionCostTotal', 'Abo-Kosten (seit Start)', '~Euro', 121);
        $this->RegisterVariableFloat('SavingsTotal', 'Ersparnis (seit Start)', '~Euro', 122);
        $this->RegisterVariableFloat('AverageIntervalDays', 'Ø Intervall (seit Start, Tage)', '', 123);

        // Fair Use status
        $this->RegisterVariableBoolean('FairUseWarning', 'Fair Use Warnung', '~Alert', 130);
        $this->RegisterVariableString('FairUseMessage', 'Fair Use Hinweis', '', 131);
    }

    public function ApplyChanges()
    {
        $this->EnsurePropertyDefinitions();
        parent::ApplyChanges();
        $this->AutoConnectParent();
        $this->MaintainProgramProfile();
        $this->EnableArchiveForHistory();
        $this->SeedHistoryIfEmpty();

        $this->SetTimerInterval('UpdateMetrics', 60 * 60 * 1000);
        $this->UpdateMetrics();
    }

    private function AutoConnectParent(): void
    {
        // Wenn schon manuell ein Gateway gesetzt ist: nichts anfassen
        $inst = IPS_GetInstance($this->InstanceID);
        $currentParent = (int)($inst['ConnectionID'] ?? 0);
        if ($currentParent > 0) {
            return;
        }

        // 1) Splitter suchen (bestehenden nutzen)
        $splitterModuleID = '{B007E66F-485C-4BCE-ACBF-256CAF2A3BED}'; // <- MrWashSplitter Module GUID
        $splitters = IPS_GetInstanceListByModuleID($splitterModuleID);

        if (count($splitters) > 0) {
            IPS_ConnectInstance($this->InstanceID, $splitters[0]);
            return;
        }

        // 2) Splitter nicht vorhanden -> anlegen
        $splitterID = IPS_CreateInstance($splitterModuleID);

        // Optional: in den gleichen Bereich im Objektbaum hängen wie das Device
        $parentObj = IPS_GetParent($this->InstanceID);
        if ($parentObj > 0) {
            IPS_SetParent($splitterID, $parentObj);
        }

        IPS_ApplyChanges($splitterID);
        IPS_ConnectInstance($this->InstanceID, $splitterID);
    }


    public function GetConfigurationForm(): string
    {
        $json = @file_get_contents(__DIR__ . '/form.json');
        return is_string($json) ? $json : json_encode(['elements'=>[], 'actions'=>[]]);
    }


    // -------------------------
    // Public API (Buttons/Form)
    // -------------------------

    public function ReceiveData($JSONString) {
        $this->Dbg('RX', 'From splitter len=' . strlen((string)$JSONString));
        $data = json_decode($JSONString);
        if (!is_object($data) || !property_exists($data, 'Buffer')) {
            return "IGNORED";
        }

        $payload = json_decode((string)$data->Buffer, true);
        if (!is_array($payload)) {
            parse_str((string)$data->Buffer, $parsed);
            if (is_array($parsed) && !empty($parsed)) {
                $payload = $parsed;
            } else {
                return "IGNORED";
        }
        }

                $this->Dbg('Payload', json_encode($payload, JSON_UNESCAPED_UNICODE));



        $entryRaw = $payload['entry'] ?? null;
        $isEntry = $this->ToBool($entryRaw);
        $timestamp = (int)($payload['_timestamp'] ?? 0);
        if ($timestamp <= 0) {
            $timestamp = $this->ParseTimestamp($payload['date'] ?? ($payload['Date'] ?? null)) ?? time();
        }
        $this->Dbg('Time', 'Event timestamp=' . $timestamp . ' (' . date('c', $timestamp) . ')');
        $locationName = trim((string)($payload['name'] ?? ''));
        $device = trim((string)($payload['device'] ?? ''));

        $this->HandleGeofencyEvent($isEntry, $timestamp, $locationName, $device, $payload);
        return "OK";
    }



    
    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'HistoryJSON':
                $this->HandleHistoryEdit((string)$Value);
                break;
            default:
                throw new Exception('Invalid Ident');
        }
    }

public function UpdateMetrics(): void
    {
        $now = time();

        $periods = $this->BuildContractPeriods($now);
        $active = $this->GetActivePeriod($periods, $now);

        $this->SetValue('ContractStart', (int)$active['start']);
        $this->SetValue('ContractEnd', (int)$active['end']);

        $prices = $this->GetPrices();
        $subscription = (float)$active['price'];

        $visits = $this->LoadVisits();

        // Visits in active period (use exit timestamp)
        $periodVisits = array_values(array_filter($visits, function (array $v) use ($active): bool {
            $exit = (int)($v['exit'] ?? 0);
            return ($exit >= (int)$active['start']) && ($exit < (int)$active['end']);
        }));

        [$countExterior, $countInterior, $equivalent] = $this->ComputeEquivalent($periodVisits, $prices);

        $total = $countExterior + $countInterior;
        $savings = $equivalent - $subscription;

        // Break-even in active period
        $remainingCost = max(0.0, $subscription - $equivalent);
        $valuePerWash = $this->GetBreakEvenValuePerWash($prices, $periodVisits);
        $needWashes = ($valuePerWash > 0.0) ? (int)ceil($remainingCost / $valuePerWash) : -1;

        $remainingDays = max(0.0, ((int)$active['end'] - $now) / 86400.0);
        $interval = ($needWashes > 0) ? ($remainingDays / $needWashes) : 0.0;

        $this->SetValue('VisitsThisPeriod', $total);
        $this->SetValue('VisitsExteriorThisPeriod', $countExterior);
        $this->SetValue('VisitsInteriorThisPeriod', $countInterior);

        $this->SetValue('EquivalentCostThisPeriod', round($equivalent, 2));
        $this->SetValue('SubscriptionCostThisPeriod', round($subscription, 2));
        $this->SetValue('SavingsThisPeriod', round($savings, 2));

        $this->SetValue('AdditionalWashesToBreakEven', $needWashes);
        $this->SetValue('RecommendedIntervalDays', round($interval, 2));

        // Totals since start (sum periods that started)
        $startTs = (int)$periods[0]['start'];
        $totalVisits = array_values(array_filter($visits, function (array $v) use ($startTs): bool {
            $exit = (int)($v['exit'] ?? 0);
            return $exit >= $startTs;
        }));

        [$tExt, $tInt, $equivTotal] = $this->ComputeEquivalent($totalVisits, $prices);
        $tCount = $tExt + $tInt;

        $subTotal = 0.0;
        foreach ($periods as $p) {
            if ((int)$p['start'] <= $now) {
                $subTotal += (float)$p['price'];
            }
        }
        $savTotal = $equivTotal - $subTotal;

        $avgInterval = $this->ComputeAverageIntervalDays($totalVisits);

        $this->SetValue('VisitsTotal', $tCount);
        $this->SetValue('VisitsExteriorTotal', $tExt);
        $this->SetValue('VisitsInteriorTotal', $tInt);
        $this->SetValue('EquivalentCostTotal', round($equivTotal, 2));
        $this->SetValue('SubscriptionCostTotal', round($subTotal, 2));
        $this->SetValue('SavingsTotal', round($savTotal, 2));
        $this->SetValue('AverageIntervalDays', round($avgInterval, 2));

        // Fair Use check (active period)
        [$fairWarn, $fairMsg] = $this->EvaluateFairUse($periodVisits, $active);
        $this->SetValue('FairUseWarning', $fairWarn);
        $this->SetValue('FairUseMessage', $fairMsg);

        // Dashboard HTML
        $html = $this->BuildDashboardHtml($active, $periodVisits, $prices, $equivalent, $subscription, $savings, $needWashes, $interval);
        $html .= $this->BuildContractTimelineHtml($now, $periods, $active);
        $html .= $this->BuildFairUseHtml($fairWarn, $fairMsg);
        $html .= $this->BuildTotalsHtml();

        $this->SetValue('Dashboard', $html);

        $summary = sprintf('%s€ (%s)', number_format($savings, 2, ',', '.'), ($savings >= 0 ? 'lohnt' : 'noch nicht'));
        $this->SetSummary($summary);
    }

    public function ClearHistory(): void
    {
        $this->WriteAttributeString('Visits', json_encode([]));
        $this->UpdateMetrics();
    }

    public function ClearOpenEntries(): void
    {
        $this->WriteAttributeString('OpenEntries', json_encode(new stdClass()));
    }

    public function RegenerateToken(): void
    {
        $newToken = $this->GenerateToken();
        IPS_SetProperty($this->InstanceID, 'Token', $newToken);
        IPS_ApplyChanges($this->InstanceID);
    }

    public function GetVisits(int $limit = 0): string
    {
        $visits = $this->LoadVisits();
        if ($limit > 0) {
            $visits = array_slice($visits, -$limit);
        }
        return json_encode($visits);
    }

    // -------------------------
    // Core logic
    // -------------------------

    private function HandleGeofencyEvent(bool $isEntry, int $timestamp, string $location, string $device, array $payload): void
    {
        if ((bool)$this->ReadPropertyBoolean('SingleEventMode')) {
            $this->Dbg('Event', 'SingleEventMode active -> count as exterior');
            // Single event always counts as exterior wash
            $visit = [
                'entry' => $timestamp,
                'exit' => $timestamp,
                'durationSec' => 0,
                'program' => 0,
                'location' => $location,
                'device' => $device,
                'singleEvent' => 1,
                'raw' => ['event' => $payload]
            ];
            $this->AppendVisit($visit);

            $this->SetValue('LastVisit', $timestamp);
            $this->SetValue('LastProgram', 0);
            $this->SetValue('LastDurationMinutes', 0);
            $this->SetValue('LastLocation', (string)$location);

            $this->UpdateMetrics();
            return;
        }

        $deviceKey = ($device !== '') ? $device : 'default';
        $open = $this->LoadOpenEntries();

        if ($isEntry) {
            $this->Dbg('Event', 'ENTRY ts=' . $timestamp . ' deviceKey=' . $deviceKey . ' location=' . $location);
            $open[$deviceKey] = [
                'ts' => $timestamp,
                'location' => $location,
                'payload' => $payload
            ];
            $this->SaveOpenEntries($open);
            return;
        }

        if (!isset($open[$deviceKey])) {
            $this->LogMessage('Exit without prior entry for device ' . $deviceKey, KL_WARNING);
            return;
        }

        $entry = $open[$deviceKey];
        unset($open[$deviceKey]);
        $this->SaveOpenEntries($open);

        $entryTs = (int)($entry['ts'] ?? 0);
        $exitTs = $timestamp;
        $this->Dbg('Event', 'EXIT ts=' . $exitTs . ' deviceKey=' . $deviceKey . ' entryTs=' . $entryTs);

        if ($entryTs <= 0 || $exitTs <= $entryTs) {
            $this->LogMessage('Invalid entry/exit timestamps', KL_WARNING);
            return;
        }

        $durationSec = $exitTs - $entryTs;
        $durationMin = (int)round($durationSec / 60);

        $thresholdSec = max(60, (int)$this->ReadPropertyInteger('InteriorThresholdMinutes') * 60);
        $program = ($durationSec >= $thresholdSec) ? 1 : 0;
        $this->Dbg('Duration', 'sec=' . $durationSec . ' min=' . $durationMin . ' thresholdSec=' . $thresholdSec . ' program=' . $program);

        $visit = [
            'entry' => $entryTs,
            'exit' => $exitTs,
            'durationSec' => $durationSec,
            'program' => $program,
            'location' => $location !== '' ? $location : (string)($entry['location'] ?? ''),
            'device' => $device,
            'singleEvent' => 0,
            'raw' => [
                'enter' => $entry['payload'] ?? null,
                'exit' => $payload
            ]
        ];

        $this->AppendVisit($visit);

        $this->SetValue('LastVisit', $exitTs);
        $this->SetValue('LastProgram', $program);
        $this->SetValue('LastDurationMinutes', $durationMin);
        $this->SetValue('LastLocation', (string)$visit['location']);

        $this->UpdateMetrics();
    }

    
    private function PruneVisits400Days(array $visits): array
    {
        $cutoff = time() - (400 * 86400);
        $out = [];
        foreach ($visits as $v) {
            $exit = (int)($v['exit'] ?? 0);
            if ($exit <= 0) {
                continue;
            }
            if ($exit >= $cutoff) {
                $out[] = $v;
            }
        }
        return $out;
    }

    private function EnableArchiveForHistory(): void
    {
        $varID = @$this->GetIDForIdent('HistoryJSON');
        if ($varID <= 0) {
            return;
        }
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}'); // Archive Control
        if (count($ids) == 0) {
            return;
        }
        $archiveID = $ids[0];
        if (!@AC_GetLoggingStatus($archiveID, $varID)) {
            @AC_SetLoggingStatus($archiveID, $varID, true);
        }
        @AC_SetGraphStatus($archiveID, $varID, false);
    }

    private function SeedHistoryIfEmpty(): void
    {
        $varID = @$this->GetIDForIdent('HistoryJSON');
        if ($varID <= 0) {
            return;
        }
        $cur = trim((string)@GetValue($varID));
        if ($cur !== '' && $cur !== '[]') {
            return;
        }
        $this->SetHistoryVariableFromVisits($this->LoadVisits());
    }

    private function SetHistoryVariableFromVisits(array $visits): void
    {
        $rows = [];
        $prices = $this->GetPrices();
        foreach ($visits as $v) {
            $exit = (int)($v['exit'] ?? 0);
            if ($exit <= 0) {
                continue;
            }
            $program = (int)($v['program'] ?? 0);
            $durMin = (int)round(((int)($v['durationSec'] ?? 0)) / 60);
            $val = ($program === 1) ? $prices['singleInterior'] : $prices['singleExterior'];

            $rows[] = [
                'timestamp'   => $exit,
                'datetime'    => date('c', $exit),
                'program'     => ($program === 1) ? 'Innen+Außen' : 'Außen',
                'durationMin' => $durMin,
                'value'       => $val,
                'location'    => (string)($v['location'] ?? ''),
                'device'      => (string)($v['device'] ?? '')
            ];
        }
        usort($rows, function (array $a, array $b): int {
            return ((int)($a['timestamp'] ?? 0)) <=> ((int)($b['timestamp'] ?? 0));
        });
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (is_string($json)) {
            $this->SetValue('HistoryJSON', $json);
        }
    }

    private function AppendHistoryVariableFromVisit(array $visit): void
    {
        $varID = @$this->GetIDForIdent('HistoryJSON');
        if ($varID <= 0) {
            return;
        }
        $current = (string)@GetValue($varID);
        $rows = json_decode($current, true);
        if (!is_array($rows)) {
            $rows = [];
        }

        $prices = $this->GetPrices();
        $exit = (int)($visit['exit'] ?? $visit['entry'] ?? 0);
        if ($exit <= 0) {
            return;
        }
        $program = (int)($visit['program'] ?? 0);
        $durMin = (int)round(((int)($visit['durationSec'] ?? 0)) / 60);
        $val = ($program === 1) ? $prices['singleInterior'] : $prices['singleExterior'];

        $rows[] = [
            'timestamp'   => $exit,
            'datetime'    => date('c', $exit),
            'program'     => ($program === 1) ? 'Innen+Außen' : 'Außen',
            'durationMin' => $durMin,
            'value'       => $val,
            'location'    => (string)($visit['location'] ?? ''),
            'device'      => (string)($visit['device'] ?? '')
        ];

        usort($rows, function (array $a, array $b): int {
            return ((int)($a['timestamp'] ?? 0)) <=> ((int)($b['timestamp'] ?? 0));
        });

        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return;
        }
        if ($current !== $json) {
            $this->SetValue('HistoryJSON', $json);
        }
    }

    private function HandleHistoryEdit(string $json): void
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new Exception('HistoryJSON muss ein JSON-Array sein');
        }

        // rebuild internal visits from the edited archive
        $visits = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ts = (int)($row['timestamp'] ?? 0);
            if ($ts <= 0) {
                continue;
            }
            $durMin = (int)($row['durationMin'] ?? 0);
            $programStr = (string)($row['program'] ?? 'Außen');
            $program = (stripos($programStr, 'innen') !== false) ? 1 : 0;

            $exit = $ts;
            $entry = $exit - max(0, $durMin) * 60;

            $visits[] = [
                'entry' => $entry,
                'exit' => $exit,
                'durationSec' => max(0, $durMin) * 60,
                'program' => $program,
                'location' => (string)($row['location'] ?? ''),
                'device' => (string)($row['device'] ?? ''),
                'singleEvent' => ($durMin <= 0) ? 1 : 0,
                'raw' => ['edited' => true]
            ];
        }

        // internal storage is limited to last 400 days
        $visits = $this->PruneVisits400Days($visits);
        $this->WriteAttributeString('Visits', json_encode($visits));

        // keep archive as provided (pretty)
        $pretty = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($pretty)) {
            $pretty = $json;
        }
        $this->SetValue('HistoryJSON', $pretty);

        $this->UpdateMetrics();
    }

private function AppendVisit(array $visit): void
    {
        $visits = $this->LoadVisits();
        $visits[] = $visit;

        // Internal storage: last 400 days only
        $visits = $this->PruneVisits400Days($visits);

        // Optional safety cap by count
        $max = max(10, (int)$this->ReadPropertyInteger('HistoryMax'));
        if (count($visits) > $max) {
            $visits = array_slice($visits, -$max);
        }

        $this->WriteAttributeString('Visits', json_encode($visits));

        // Archive variable keeps full history (no retention)
        $this->AppendHistoryVariableFromVisit($visit);
    }


    private function LoadVisits(): array
    {
        $json = (string)$this->ReadAttributeString('Visits');
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function LoadOpenEntries(): array
    {
        $json = (string)$this->ReadAttributeString('OpenEntries');
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function SaveOpenEntries(array $open): void
    {
        $this->WriteAttributeString('OpenEntries', json_encode($open));
    }

    // -------------------------
    // Contract periods
    // -------------------------

    private function BuildContractPeriods(int $now): array
    {
        $startTs = $this->ParseStartDateTs();
        $baseTerm = (int)$this->ReadPropertyInteger('BillingCycle');
        $basePrice = $this->GetSubscriptionPriceForTerm($baseTerm);

        // Build initial period
        $periods = [];
        $pStart = $startTs;
        $pEnd = $this->AddTermTs($pStart, $baseTerm, 0);
        $periods[] = [
            'start' => $pStart,
            'end' => $pEnd,
            'term' => $baseTerm,
            'price' => $basePrice,
            'bonusDays' => 0,
            'source' => 'initial'
        ];

        $renewals = $this->ReadRenewals();
        usort($renewals, function (array $a, array $b): int {
            return ((int)$a['dateTs']) <=> ((int)$b['dateTs']);
        });

        foreach ($renewals as $r) {
            $rDate = (int)$r['dateTs'];
            $term = (int)$r['term'];
            $price = (float)$r['price'];
            $useBonus = (bool)$r['bonus'];

            if ($price <= 0) {
                $price = $this->GetSubscriptionPriceForTerm($term);
            }

            $last = $periods[count($periods) - 1];
            $lastEnd = (int)$last['end'];

            $newStart = $lastEnd;
            $bonusDays = 0;

            if ($rDate > $lastEnd) {
                // gap: new contract starts at renewal date
                $newStart = $rDate;
            } else {
                // renewed before end -> eligible for bonus
                if ((bool)$this->ReadPropertyBoolean('RenewalBonusEnabled') && $useBonus) {
                    $bonusDays = $this->GetBonusDaysForTerm($term);
                }
            }

            $newEnd = $this->AddTermTs($newStart, $term, $bonusDays);

            $periods[] = [
                'start' => $newStart,
                'end' => $newEnd,
                'term' => $term,
                'price' => $price,
                'bonusDays' => $bonusDays,
                'source' => 'renewal',
                'renewalDate' => $rDate
            ];
        }

        return $periods;
    }

    private function GetActivePeriod(array $periods, int $now): array
    {
        foreach ($periods as $p) {
            if ($now >= (int)$p['start'] && $now < (int)$p['end']) {
                return $p;
            }
        }
        // If none active, return last (best effort)
        return $periods[count($periods) - 1];
    }

    private function ParseStartDateTs(): int
    {
        $tz = new DateTimeZone(@date_default_timezone_get() ?: 'Europe/Berlin');
        $startStr = trim((string)$this->ReadPropertyString('StartDate'));
        // SelectDate stores JSON like {"day":..,"month":..,"year":..}
        $decoded = json_decode($startStr, true);
        if (is_array($decoded) && isset($decoded['year'], $decoded['month'], $decoded['day'])) {
            $startStr = sprintf('%04d-%02d-%02d', (int)$decoded['year'], (int)$decoded['month'], (int)$decoded['day']);
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', substr($startStr, 0, 10), $tz);
        if ($dt === false) {
            $ts = strtotime($startStr);
            $dt = ($ts !== false) ? (new DateTimeImmutable('@' . $ts))->setTimezone($tz) : new DateTimeImmutable('now', $tz);
        }
        $dt = $dt->setTime(0, 0, 0);
        return $dt->getTimestamp();
    }

    private function AddTermTs(int $startTs, int $term, int $bonusDays): int
    {
        $tz = new DateTimeZone(@date_default_timezone_get() ?: 'Europe/Berlin');
        $dt = (new DateTimeImmutable('@' . $startTs))->setTimezone($tz);

        if ($term === self::TERM_YEARLY) {
            $dt2 = $dt->modify('+1 year');
        } elseif ($term === self::TERM_CUSTOM_DAYS) {
            $days = max(1, (int)$this->ReadPropertyInteger('BillingCycleDays'));
            $dt2 = $dt->modify('+' . $days . ' day');
        } elseif ($term === self::TERM_FLATWASH_3M) {
            $dt2 = $dt->modify('+93 day');
        } elseif ($term === self::TERM_FLATWASH_12M) {
            $dt2 = $dt->modify('+372 day');
        } else {
            $dt2 = $dt->modify('+1 month');
        }

        if ($bonusDays > 0) {
            $dt2 = $dt2->modify('+' . $bonusDays . ' day');
        }

        return $dt2->getTimestamp();
    }

    private function GetSubscriptionPriceForTerm(int $term): float
    {
        if ($term === self::TERM_FLATWASH_3M) {
            $p = (float)$this->ReadPropertyFloat('SubscriptionPrice3M');
            return $p > 0 ? $p : (float)$this->ReadPropertyFloat('SubscriptionPrice');
        }
        if ($term === self::TERM_FLATWASH_12M) {
            $p = (float)$this->ReadPropertyFloat('SubscriptionPrice12M');
            return $p > 0 ? $p : (float)$this->ReadPropertyFloat('SubscriptionPrice');
        }
        return (float)$this->ReadPropertyFloat('SubscriptionPrice');
    }

    private function GetBonusDaysForTerm(int $term): int
    {
        // Only for Flatwash offers (best effort mapping)
        if ($term === self::TERM_FLATWASH_3M) {
            return 14; // 2 weeks
        }
        if ($term === self::TERM_FLATWASH_12M) {
            return 62; // ~2 months (31-day month scheme)
        }
        return 0;
    }

    private function ReadRenewals(): array
    {
        $raw = (string)$this->ReadPropertyString('Renewals');
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return [];
        }

        $out = [];
        foreach ($arr as $row) {
            if (!is_array($row)) {
                continue;
            }
            $dateStr = (string)($row['Date'] ?? $row['date'] ?? '');
            $term = (int)($row['Term'] ?? $row['term'] ?? 0);
            $price = (float)($row['Price'] ?? $row['price'] ?? 0);
            $bonus = (bool)($row['Bonus'] ?? $row['bonus'] ?? false);

            $ts = strtotime($dateStr);
            if ($ts === false) {
                continue;
            }
            // Normalize to day start
            $dt = (new DateTimeImmutable('@' . $ts))->setTime(0, 0, 0);
            $out[] = [
                'dateTs' => $dt->getTimestamp(),
                'term' => $term,
                'price' => $price,
                'bonus' => $bonus
            ];
        }
        return $out;
    }

    // -------------------------
    // Calculations & Fair Use
    // -------------------------

    private function GetPrices(): array
    {
        return [
            'singleExterior' => (float)$this->ReadPropertyFloat('SinglePriceExterior'),
            'singleInterior' => (float)$this->ReadPropertyFloat('SinglePriceInterior')
        ];
    }

    private function ComputeEquivalent(array $visits, array $prices): array
    {
        $countExterior = 0;
        $countInterior = 0;
        $equivalent = 0.0;

        foreach ($visits as $v) {
            $program = (int)($v['program'] ?? 0);
            if ($program === 1) {
                $countInterior++;
                $equivalent += $prices['singleInterior'];
            } else {
                $countExterior++;
                $equivalent += $prices['singleExterior'];
            }
        }
        return [$countExterior, $countInterior, $equivalent];
    }

    private function GetBreakEvenValuePerWash(array $prices, array $periodVisits): float
    {
        $assumption = (int)$this->ReadPropertyInteger('BreakEvenAssumption');

        if ($assumption === 2) {
            return max(0.0, (float)$prices['singleExterior']);
        }
        if ($assumption === 3) {
            return max(0.0, (float)$prices['singleInterior']);
        }
        if ($assumption === 1) {
            if (count($periodVisits) === 0) {
                $assumption = 0;
            } else {
                $sum = 0.0;
                foreach ($periodVisits as $v) {
                    $program = (int)($v['program'] ?? 0);
                    $sum += ($program === 1) ? $prices['singleInterior'] : $prices['singleExterior'];
                }
                return max(0.0, $sum / count($periodVisits));
            }
        }

        $plan = (int)$this->ReadPropertyInteger('Plan');
        return max(0.0, $plan === 1 ? (float)$prices['singleInterior'] : (float)$prices['singleExterior']);
    }

    private function ComputeAverageIntervalDays(array $visits): float
    {
        $count = count($visits);
        if ($count < 2) {
            return 0.0;
        }
        usort($visits, function (array $a, array $b): int {
            return ((int)($a['exit'] ?? 0)) <=> ((int)($b['exit'] ?? 0));
        });
        $first = (int)($visits[0]['exit'] ?? 0);
        $last = (int)($visits[$count - 1]['exit'] ?? 0);
        $spanDays = max(0.0, ($last - $first) / 86400.0);
        return $spanDays / max(1, ($count - 1));
    }

    private function EvaluateFairUse(array $periodVisits, array $active): array
    {
        if (!(bool)$this->ReadPropertyBoolean('EnableFairUseWarnings')) {
            return [false, ''];
        }

        $reasons = [];
        $count = count($periodVisits);
        $days = max(1.0, ((int)$active['end'] - (int)$active['start']) / 86400.0);
        $avgPerWeek = $count / ($days / 7.0);

        $maxPerWeek = (float)$this->ReadPropertyFloat('FairUseMaxPerWeek');
        if ($maxPerWeek > 0 && $avgPerWeek > $maxPerWeek) {
            $reasons[] = sprintf('Ø %.2f Besuche/Woche (Schwellwert %.2f)', $avgPerWeek, $maxPerWeek);
        }

        $avgInterval = $this->ComputeAverageIntervalDays($periodVisits);
        $minInterval = (float)$this->ReadPropertyFloat('FairUseMinAvgIntervalDays');
        if ($minInterval > 0 && $avgInterval > 0 && $avgInterval < $minInterval) {
            $reasons[] = sprintf('Ø Intervall %.2f Tage (Schwellwert %.2f Tage)', $avgInterval, $minInterval);
        }

        $term = (int)($active['term'] ?? self::TERM_MONTHLY);
        if ($term === self::TERM_FLATWASH_3M) {
            $m = (int)$this->ReadPropertyInteger('FairUseMaxUses3M');
            if ($m > 0 && $count > $m) {
                $reasons[] = sprintf('%d Nutzungen im 3M-Zeitraum (Schwellwert %d)', $count, $m);
            }
        } elseif ($term === self::TERM_FLATWASH_12M) {
            $m = (int)$this->ReadPropertyInteger('FairUseMaxUses12M');
            if ($m > 0 && $count > $m) {
                $reasons[] = sprintf('%d Nutzungen im 12M-Zeitraum (Schwellwert %d)', $count, $m);
            }
        }

        if (count($reasons) === 0) {
            return [false, ''];
        }

        $msg = "Auffällige Nutzung erkannt:\n- " . implode("\n- ", $reasons)
            . "\n\nHinweis: Das sind Warnindikatoren. Wenn du \"übertreibst\", kann das bei Flatrates/AGB als unangemessene Nutzung gewertet werden.";
        return [true, $msg];
    }

    // -------------------------
    // Dashboard HTML
    // -------------------------

    private function BuildDashboardHtml(array $active, array $periodVisits, array $prices, float $equivalent, float $subscription, float $savings, int $needWashes, float $intervalDays): string
    {
        $C_TEXT = '#e5e7eb';
        $C_MUTED = '#94a3b8';
        $C_BORDER = 'rgba(255,255,255,.10)';
        $C_CARD = 'rgba(255,255,255,.03)';

        $BADGE_OK_BG = '#065f46';    // dunkles grün
        $BADGE_BAD_BG = '#7f1d1d';   // dunkles rot
        $BADGE_WARN_BG = '#92400e';  // dunkles amber
        $BADGE_INFO_BG = '#1e40af';  // dunkles blau
        $BADGE_TXT = '#ffffff';

        $fmtDate = function (int $ts): string {
            return date('d.m.Y H:i', $ts);
        };
        $fmtDateShort = function (int $ts): string {
            return date('d.m.Y', $ts);
        };
        $fmtMoney = function (float $v): string {
            return number_format($v, 2, ',', '.') . ' €';
        };

        $periodStart = (int)$active['start'];
        $periodEnd = (int)$active['end'];
        $periodDays = max(1.0, ($periodEnd - $periodStart) / 86400.0);

        $plan = (int)$this->ReadPropertyInteger('Plan');
        $singleForPlan = ($plan === 1) ? $prices['singleInterior'] : $prices['singleExterior'];
        $requiredPerPeriod = ($singleForPlan > 0) ? (int)ceil($subscription / $singleForPlan) : 0;
        $idealInterval = ($requiredPerPeriod > 0) ? ($periodDays / $requiredPerPeriod) : 0.0;

        $savingsBadge = ($savings >= 0)
          ? '<span style="padding:2px 8px;border-radius:999px;background:#065f46;color:#fff;font-weight:600;">lohnt</span>'
          : '<span style="padding:2px 8px;border-radius:999px;background:#7f1d1d;color:#fff;font-weight:600;">noch nicht</span>';

        $needTxt = ($needWashes >= 0) ? (string)$needWashes : '—';
        $intervalTxt = ($needWashes > 0) ? (number_format($intervalDays, 2, ',', '.') . ' Tage') : '—';

        // Last 10 visits in period
        $rows = '';
        $last = array_slice($periodVisits, -10);
        foreach (array_reverse($last) as $v) {
            $exit = (int)($v['exit'] ?? 0);
            $dur = (int)round(((int)($v['durationSec'] ?? 0)) / 60);
            $prog = (int)($v['program'] ?? 0);
            $progTxt = ($prog === 1) ? 'Innen+Außen' : 'Außen';
            $val = ($prog === 1) ? $prices['singleInterior'] : $prices['singleExterior'];
            $loc = htmlspecialchars((string)($v['location'] ?? ''), ENT_QUOTES);

            $rows .= '<tr>'
                . '<td style="padding:4px 6px;border-bottom:1px solid #eee;">' . htmlspecialchars($fmtDate($exit), ENT_QUOTES) . '</td>'
                . '<td style="padding:4px 6px;border-bottom:1px solid #eee;">' . $progTxt . '</td>'
                . '<td style="padding:4px 6px;border-bottom:1px solid #eee;text-align:right;">' . $dur . '</td>'
                . '<td style="padding:4px 6px;border-bottom:1px solid #eee;text-align:right;">' . htmlspecialchars($fmtMoney((float)$val), ENT_QUOTES) . '</td>'
                . '<td style="padding:4px 6px;border-bottom:1px solid #eee;">' . $loc . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" style="padding:6px;color:#666;">Noch keine Besuche im aktuellen Zeitraum.</td></tr>';
        }

        $html = '<div style="font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial;line-height:1.35;">'
            . '<div style="font-size:18px;font-weight:700;margin-bottom:6px;">Mr. Wash Flatrate – Auswertung</div>'
            . '<div style="color:#555;margin-bottom:10px;">Zeitraum: <b>' . htmlspecialchars($fmtDateShort($periodStart), ENT_QUOTES) . '</b> – <b>' . htmlspecialchars($fmtDateShort($periodEnd - 1), ENT_QUOTES) . '</b></div>'
            . '<table style="border-collapse:collapse;width:100%;max-width:980px;">'
            . '<tr>'
            . '<td style="padding:6px;border:1px solid #eee;">Abo-Kosten</td>'
            . '<td style="padding:6px;border:1px solid #eee;text-align:right;">' . htmlspecialchars($fmtMoney($subscription), ENT_QUOTES) . '</td>'
            . '<td style="padding:6px;border:1px solid #eee;">Kosten ohne Abo (laut Besuchen)</td>'
            . '<td style="padding:6px;border:1px solid #eee;text-align:right;">' . htmlspecialchars($fmtMoney($equivalent), ENT_QUOTES) . '</td>'
            . '</tr>'
            . '<tr>'
            . '<td style="padding:6px;border:1px solid #eee;">Ersparnis</td>'
            . '<td style="padding:6px;border:1px solid #eee;text-align:right;">' . htmlspecialchars($fmtMoney($savings), ENT_QUOTES) . '</td>'
            . '<td style="padding:6px;border:1px solid #eee;">Status</td>'
            . '<td style="padding:6px;border:1px solid #eee;text-align:right;">' . $savingsBadge . '</td>'
            . '</tr>'
            . '</table>'
            . '<div style="margin-top:10px;max-width:980px;">'
            . '<div style="font-weight:700;margin-bottom:6px;">Fortschritt & Intervall</div>'
            . $this->BuildProgressHtml($periodVisits, $equivalent, $subscription, $requiredPerPeriod, $idealInterval, $needWashes, $intervalDays)
            . '</div>'
            . '<div style="margin-top:10px;">'
            . '<b>Break-Even:</b> noch <b>' . htmlspecialchars($needTxt, ENT_QUOTES) . '</b> Wäsche(n) bis mindestens 0€ – empfohlen: etwa alle <b>' . htmlspecialchars($intervalTxt, ENT_QUOTES) . '</b>'
            . '</div>'
            . '<div style="margin-top:6px;color:#555;">'
            . 'Theorie: Für Break-Even brauchst du ca. <b>' . htmlspecialchars((string)$requiredPerPeriod, ENT_QUOTES) . '</b> Wäschen pro Zeitraum ('
            . ($idealInterval > 0 ? '≈ alle <b>' . htmlspecialchars(number_format($idealInterval, 2, ',', '.'), ENT_QUOTES) . '</b> Tage' : 'kein Preis hinterlegt')
            . ').'
            . '</div>'
            . '<div style="margin-top:14px;font-weight:700;">Letzte Besuche (max. 10, aktueller Zeitraum)</div>'
            . '<table style="border-collapse:collapse;width:100%;max-width:980px;font-size:13px;">'
            . '<thead><tr>'
            . '<th style="text-align:left;padding:4px 6px;border-bottom:2px solid #ddd;">Zeit</th>'
            . '<th style="text-align:left;padding:4px 6px;border-bottom:2px solid #ddd;">Programm</th>'
            . '<th style="text-align:right;padding:4px 6px;border-bottom:2px solid #ddd;">Dauer (min)</th>'
            . '<th style="text-align:right;padding:4px 6px;border-bottom:2px solid #ddd;">Wert</th>'
            . '<th style="text-align:left;padding:4px 6px;border-bottom:2px solid #ddd;">Location</th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>'
            . '</div>';

        return $html;
    }

    private function BuildProgressHtml(array $periodVisits, float $equivalent, float $subscription, int $requiredPerPeriod, float $idealIntervalDays, int $needWashes, float $recommendedIntervalDays): string
    {
        $count = count($periodVisits);

        $progressCost = ($subscription > 0.0) ? max(0.0, min(100.0, ($equivalent / $subscription) * 100.0)) : 0.0;
        $progressVisits = ($requiredPerPeriod > 0) ? max(0.0, min(100.0, ($count / $requiredPerPeriod) * 100.0)) : 0.0;

        $bar = function (float $pct): string {
            $pct = max(0.0, min(100.0, $pct));
            return '<div style="height:10px;background:#eee;border-radius:999px;overflow:hidden;">'
                . '<div style="height:10px;width:' . number_format($pct, 2, '.', '') . '%;background:#3b82f6;"></div>'
                . '</div>';
        };

        $avgInterval = $this->ComputeAverageIntervalDays($periodVisits);

        $pill = function (string $text, string $bg): string {
            return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:' . $bg . ';">' . htmlspecialchars($text, ENT_QUOTES) . '</span>';
        };

        $intervalPill = $pill('—', 'rgba(255,255,255,.08)', '#e5e7eb');
        if ($idealIntervalDays > 0.0 && $avgInterval > 0.0) {
            $ratio = $avgInterval / $idealIntervalDays;
            if ($ratio >= 0.7 && $ratio <= 1.3) {
                $intervalPill = $pill('im Plan', '#065f46');
            } elseif ($ratio < 0.7) {
                $intervalPill = $pill('zu häufig', '#92400e');
            } else {
                $intervalPill = $pill('zu selten', '#7f1d1d');
            }
        } elseif ($idealIntervalDays > 0.0 && $count === 0) {
            $intervalPill = $pill('noch kein Besuch', 'rgba(255,255,255,.08)', '#e5e7eb');
        }

        $nextRecommended = ($needWashes > 0 && $recommendedIntervalDays > 0) ? (time() + (int)round($recommendedIntervalDays * 86400)) : 0;

        $html = ''
            . '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;max-width:980px;">'
            . '<div style="padding:10px;border:1px solid #eee;border-radius:10px;">'
            . '<div style="font-size:12px;color:#555;margin-bottom:6px;">Kosten-Fortschritt</div>'
            . $bar($progressCost)
            . '<div style="margin-top:6px;font-size:12px;color:#555;">' . number_format($progressCost, 0, ',', '.') . '%</div>'
            . '</div>'
            . '<div style="padding:10px;border:1px solid #eee;border-radius:10px;">'
            . '<div style="font-size:12px;color:#555;margin-bottom:6px;">Besuche-Fortschritt</div>'
            . $bar($progressVisits)
            . '<div style="margin-top:6px;font-size:12px;color:#555;">' . (int)$count . ' / ' . (int)$requiredPerPeriod . '</div>'
            . '</div>'
            . '</div>'
            . '<div style="margin-top:10px;display:flex;gap:14px;flex-wrap:wrap;align-items:center;max-width:980px;">'
            . '<div style="font-size:12px;color:#555;">Ø Intervall: <b>' . ($avgInterval > 0 ? number_format($avgInterval, 2, ',', '.') . ' Tage' : '—') . '</b> · Ziel: <b>' . ($idealIntervalDays > 0 ? number_format($idealIntervalDays, 2, ',', '.') . ' Tage' : '—') . '</b></div>'
            . '<div>' . $intervalPill . '</div>';

        if ($nextRecommended > 0) {
            $html .= '<div style="font-size:12px;color:#555;">Nächstes empfohlenes Datum: <b>' . date('d.m.Y', $nextRecommended) . '</b></div>';
        }

        $html .= '</div>';

        return $html;
    }

    private function BuildContractTimelineHtml(int $now, array $periods, array $active): string
    {
        $fmtDate = function (int $ts): string {
            return date('d.m.Y', $ts);
        };
        $fmtMoney = function (float $v): string {
            return number_format($v, 2, ',', '.') . ' €';
        };

        $termLabel = function (int $term): string {
            if ($term === self::TERM_FLATWASH_3M) {
                return 'FLATWASH 3 Monate (93 Tage)';
            }
            if ($term === self::TERM_FLATWASH_12M) {
                return 'FLATWASH 12 Monate (372 Tage)';
            }
            if ($term === self::TERM_YEARLY) {
                return 'Jährlich';
            }
            if ($term === self::TERM_CUSTOM_DAYS) {
                $days = max(1, (int)$this->ReadPropertyInteger('BillingCycleDays'));
                return 'Benutzerdefiniert (' . $days . ' Tage)';
            }
            return 'Monatlich';
        };

        // determine active index
        $activeIdx = 0;
        foreach ($periods as $i => $p) {
            if ((int)$p['start'] === (int)$active['start'] && (int)$p['end'] === (int)$active['end']) {
                $activeIdx = (int)$i;
                break;
            }
        }

        $from = max(0, $activeIdx - 1);
        $to = min(count($periods) - 1, $activeIdx + 2);

        $rows = '';
        for ($i = $from; $i <= $to; $i++) {
            $p = $periods[$i];
            $isActive = ($i === $activeIdx);
            $bg = $isActive ? 'background:rgba(59,130,246,.15);' : '';
            $badge = $isActive
              ? '<span style="padding:2px 10px;border-radius:999px;background:#1e40af;color:#fff;font-weight:600;">aktiv</span>'
              : '';
            $bonusDays = (int)($p['bonusDays'] ?? 0);
            $bonusTxt = ($bonusDays > 0) ? ('+' . $bonusDays . ' Tage') : '—';

            $gapTxt = '—';
            if ($i > 0) {
                $prev = $periods[$i - 1];
                $gapSec = (int)$p['start'] - (int)$prev['end'];
                if ($gapSec > 0) {
                    $gapTxt = '+' . number_format($gapSec / 86400.0, 0, ',', '.') . ' Tage Pause';
                } elseif ($gapSec < 0) {
                    $gapTxt = 'Überlappung';
                }
            }

            $rows .= '<tr style="' . $bg . '">'
                . '<td style="padding:4px 6px;border-bottom:1px solid #eee;">' . htmlspecialchars($fmtDate((int)$p['start']), ENT_QUOTES) . '</td>'
                . '<td style="padding:4px 6px;border-bottom:1px solid #eee;">' . htmlspecialchars($fmtDate((int)$p['end'] - 1), ENT_QUOTES) . '</td>'
                . '<td style="padding:4px 6px;border-bottom:1px solid #eee;">' . htmlspecialchars($termLabel((int)$p['term']), ENT_QUOTES) . '</td>'
                . '<td style="padding:4px 6px;border-bottom:1px solid #eee;text-align:right;">' . htmlspecialchars($fmtMoney((float)$p['price']), ENT_QUOTES) . '</td>'
                . '<td style="padding:4px 6px;border-bottom:1px solid #eee;text-align:right;">' . htmlspecialchars($bonusTxt, ENT_QUOTES) . '</td>'
                . '<td style="padding:4px 6px;border-bottom:1px solid #eee;">' . htmlspecialchars($gapTxt, ENT_QUOTES) . '</td>'
                . '<td style="padding:4px 6px;border-bottom:1px solid #eee;text-align:right;">' . $badge . '</td>'
                . '</tr>';
        }

        $activeEnd = (int)($active['end'] ?? 0);
        $bonusEnabled = (bool)$this->ReadPropertyBoolean('RenewalBonusEnabled');
        $bonusNote = $bonusEnabled ? 'Bonus aktiv (bei Verlängerung vor Ablauf).' : 'Bonus deaktiviert.';

        $html = '<div style="margin-top:12px;max-width:980px;">'
            . '<div style="font-weight:700;margin-bottom:6px;">Vertrags-Timeline</div>'
            . '<div style="color:#555;margin-bottom:6px;">Aktueller Zeitraum endet am <b>' . htmlspecialchars($fmtDate($activeEnd - 1), ENT_QUOTES) . '</b>. ' . htmlspecialchars($bonusNote, ENT_QUOTES) . '</div>'
            . '<table style="border-collapse:collapse;width:100%;font-size:13px;">'
            . '<thead><tr>'
            . '<th style="text-align:left;padding:4px 6px;border-bottom:2px solid #ddd;">Start</th>'
            . '<th style="text-align:left;padding:4px 6px;border-bottom:2px solid #ddd;">Ende</th>'
            . '<th style="text-align:left;padding:4px 6px;border-bottom:2px solid #ddd;">Laufzeit</th>'
            . '<th style="text-align:right;padding:4px 6px;border-bottom:2px solid #ddd;">Preis</th>'
            . '<th style="text-align:right;padding:4px 6px;border-bottom:2px solid #ddd;">Bonus</th>'
            . '<th style="text-align:left;padding:4px 6px;border-bottom:2px solid #ddd;">Hinweis</th>'
            . '<th style="text-align:right;padding:4px 6px;border-bottom:2px solid #ddd;"></th>'
            . '</tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '</table>'
            . '</div>';

        return $html;
    }

    private function BuildFairUseHtml(bool $warn, string $msg): string
    {
        if (!$warn) {
            return '';
        }
        $safe = nl2br(htmlspecialchars($msg, ENT_QUOTES));
        return '<div style="margin-top:12px;max-width:980px;padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,.10);background:rgba(245,158,11,.18);">'
            . '<div style="font-weight:700;margin-bottom:6px;">Warnung: Nutzungsbedingungen / Fair Use</div>'
            . '<div style="font-size:13px;color:#e5e7eb;">'
            . '</div>';
    }

    private function BuildTotalsHtml(): string
    {
        $C_TEXT = '#e5e7eb';
        $C_MUTED = '#94a3b8';
        $C_BORDER = 'rgba(255,255,255,.10)';
        $C_CARD = 'rgba(255,255,255,.03)';

        $BADGE_OK_BG = '#065f46';    // dunkles grün
        $BADGE_BAD_BG = '#7f1d1d';   // dunkles rot
        $BADGE_WARN_BG = '#92400e';  // dunkles amber
        $BADGE_INFO_BG = '#1e40af';  // dunkles blau
        $BADGE_TXT = '#ffffff';

        $fmtMoney = function (float $v): string {
            return number_format($v, 2, ',', '.') . ' €';
        };

        $visits = (int)$this->GetValue('VisitsTotal');
        $ext = (int)$this->GetValue('VisitsExteriorTotal');
        $int = (int)$this->GetValue('VisitsInteriorTotal');
        $equiv = (float)$this->GetValue('EquivalentCostTotal');
        $sub = (float)$this->GetValue('SubscriptionCostTotal');
        $sav = (float)$this->GetValue('SavingsTotal');
        $avgInt = (float)$this->GetValue('AverageIntervalDays');

        $badge = ($sav >= 0)
            ? '<span style="padding:2px 8px;border-radius:999px;background:#065f46;color:#fff;font-weight:600;">lohnt</span>'
            : '<span style="padding:2px 8px;border-radius:999px;background:#7f1d1d;color:#fff;font-weight:600;">noch nicht</span>';

        $html = '<div style="margin-top:18px;border-top:2px solid #eee;padding-top:12px;max-width:980px;">'
            . '<div style="font-weight:700;margin-bottom:6px;">Seit Start</div>'
            . '<table style="border-collapse:collapse;width:100%;">'
            . '<tr>'
            . '<td style="padding:6px;border:1px solid #eee;">Besuche</td>'
            . '<td style="padding:6px;border:1px solid #eee;text-align:right;">' . $visits . ' (Außen: ' . $ext . ', Innen: ' . $int . ')</td>'
            . '<td style="padding:6px;border:1px solid #eee;">Ø Intervall</td>'
            . '<td style="padding:6px;border:1px solid #eee;text-align:right;">' . ($avgInt > 0 ? number_format($avgInt, 2, ',', '.') . ' Tage' : '—') . '</td>'
            . '</tr>'
            . '<tr>'
            . '<td style="padding:6px;border:1px solid #eee;">Kosten ohne Abo</td>'
            . '<td style="padding:6px;border:1px solid #eee;text-align:right;">' . htmlspecialchars($fmtMoney($equiv), ENT_QUOTES) . '</td>'
            . '<td style="padding:6px;border:1px solid #eee;">Abo-Kosten</td>'
            . '<td style="padding:6px;border:1px solid #eee;text-align:right;">' . htmlspecialchars($fmtMoney($sub), ENT_QUOTES) . '</td>'
            . '</tr>'
            . '<tr>'
            . '<td style="padding:6px;border:1px solid #eee;">Ersparnis</td>'
            . '<td style="padding:6px;border:1px solid #eee;text-align:right;">' . htmlspecialchars($fmtMoney($sav), ENT_QUOTES) . '</td>'
            . '<td style="padding:6px;border:1px solid #eee;">Status</td>'
            . '<td style="padding:6px;border:1px solid #eee;text-align:right;">' . $badge . '</td>'
            . '</tr>'
            . '</table>'
            . '</div>';

        return $html;
    }

    // -------------------------
    // Helpers
    // -------------------------

    private function MaintainProgramProfile(): void
    {
        if (!IPS_VariableProfileExists(self::PROFILE_PROGRAM)) {
            IPS_CreateVariableProfile(self::PROFILE_PROGRAM, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation(self::PROFILE_PROGRAM, 0, 'Außen', '', 0xFFFFFF);
        IPS_SetVariableProfileAssociation(self::PROFILE_PROGRAM, 1, 'Innen+Außen', '', 0xFFFFFF);
        IPS_SetVariableProfileAssociation(self::PROFILE_PROGRAM, 2, 'Unklar', '', 0xFFFFFF);
    }

    private function NormalizeHookPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/hook/mrwash';
        }
        if (strpos($path, '/hook/') !== 0) {
            $path = '/hook/' . ltrim($path, '/');
        }
        return $path;
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

    private function ReadIncomingPayload(): ?array
    {
        if (!empty($_POST) && is_array($_POST)) {
            return $_POST;
        }

        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            $tmp = $_GET;
            unset($tmp['token']);
            unset($tmp['action']);
            unset($tmp['format']);
            unset($tmp['limit']);
            return !empty($tmp) ? $tmp : null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        parse_str($raw, $parsed);
        if (is_array($parsed) && !empty($parsed)) {
            return $parsed;
        }

        return null;
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
        if (ctype_digit($v)) {
            $ts = (int)$v;
            if ($ts > 1000000000) {
                return $ts;
            }
        }
        $ts = strtotime($v);
        return ($ts !== false) ? $ts : null;
    }

    private function ToBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $v = strtolower(trim((string)$value));
        return in_array($v, ['1', 'true', 'yes', 'on', 'enter'], true);
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

    private function EnsurePropertyDefinitions(): void
    {
        // Register missing properties only (avoid "already registered" warnings)
        if (@IPS_GetProperty($this->InstanceID, 'EnableDebug') === false) {
        $this->RegisterPropertyBoolean('EnableDebug', true);
        }
        if (@IPS_GetProperty($this->InstanceID, 'SinglePriceExterior') === false) {
            $this->RegisterPropertyFloat('SinglePriceExterior', 0.0);
        }
        if (@IPS_GetProperty($this->InstanceID, 'SinglePriceInterior') === false) {
            $this->RegisterPropertyFloat('SinglePriceInterior', 0.0);
        }
        if (@IPS_GetProperty($this->InstanceID, 'InteriorThresholdMinutes') === false) {
            // Default jetzt 15 Minuten
            $this->RegisterPropertyInteger('InteriorThresholdMinutes', 15);
        }
        if (@IPS_GetProperty($this->InstanceID, 'HistoryMax') === false) {
            $this->RegisterPropertyInteger('HistoryMax', 200);
        }

        // Attributes (suppress warnings when missing)
        $visits = @$this->ReadAttributeString('Visits');
        if (!is_string($visits) || $visits === '') {
            $this->RegisterAttributeString('Visits', json_encode([]));
        }

        $open = @$this->ReadAttributeString('OpenEntries');
        if (!is_string($open) || $open === '') {
            $this->RegisterAttributeString('OpenEntries', json_encode(new stdClass()));
        }

        // Make sure HistoryJSON variable exists (safe to call in ApplyChanges)
        if (@$this->GetIDForIdent('HistoryJSON') === 0) {
            $this->RegisterVariableString('HistoryJSON', 'History (JSON)', '', 105);
            $this->EnableAction('HistoryJSON');
        }
    }


}
