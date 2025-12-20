<?php declare(strict_types=1);

class MrWashSplitter extends IPSModule
{
    private const IFACE_TO_DEVICE = '{927CEF32-FF05-4518-95CD-1F5709CF7FA2}';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('EnableDebug', true);
}

    public function ApplyChanges()
    {
        $this->EnsurePropertyDefinitions();
        parent::ApplyChanges();
        $this->AutoConnectParent();
    }

    public function ReceiveData($JSONString)
    {
        $this->Dbg('RX', 'From parent len=' . strlen((string)$JSONString));

        $data = json_decode($JSONString);
        if (!is_object($data)) {
            $this->Dbg('RX', 'Invalid JSON from parent');
            return "IGNORED";
        }
    
        $this->Dbg('RX', $data);

        if (!property_exists($data, 'Buffer')) {
            $this->Dbg('RX', 'No Buffer property');
            return "IGNORED";
        }

        $buf = (string)$data->Buffer;
        $this->Dbg('BufferPreview', substr($buf, 0, 160));

        $this->Dbg('Forward', 'To children DataID ' . self::IFACE_TO_DEVICE);

        $this->SendDataToChildren(json_encode([
            'DataID' => self::IFACE_TO_DEVICE,
            'Buffer' => $buf
        ]));

        return "OK";
    }

    public function ForwardData($JSONString)
    {
        $data = json_decode($JSONString);
        $this->Dbg('TX', 'From child len=' . strlen((string)$JSONString));
        if (!is_object($data) || !property_exists($data, 'Buffer')) {
            return '';
        }

        // Optional: forward data from device to parent (not needed for MrWash, keep as debug)
        // $this->SendDataToParent(json_encode(['DataID' => self::IFACE_FROM_PARENT, 'Buffer' => (string)$data->Buffer]));
        return 'OK';
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

    private function AutoConnectParent(): void
    {
        $inst = IPS_GetInstance($this->InstanceID);
        $currentParent = (int)($inst['ConnectionID'] ?? 0);
        if ($currentParent > 0) {
            return;
        }

        $ioModuleID = '{79BC040B-295B-40BD-982E-82238FDB2B10}'; // <- MrWashWebhookIO Module GUID
        $ios = IPS_GetInstanceListByModuleID($ioModuleID);

        if (count($ios) > 0) {
            IPS_ConnectInstance($this->InstanceID, $ios[0]);
            return;
        }

        $ioID = IPS_CreateInstance($ioModuleID);

        $parentObj = IPS_GetParent($this->InstanceID);
        if ($parentObj > 0) {
            IPS_SetParent($ioID, $parentObj);
        }

        IPS_ApplyChanges($ioID);
        IPS_ConnectInstance($this->InstanceID, $ioID);
    }

    private function EnsurePropertyDefinitions(): void
    {
        // Nur fehlende Properties nachziehen (verhindert "already registered")
        if (@IPS_GetProperty($this->InstanceID, 'EnableDebug') === false) {
            $this->RegisterPropertyBoolean('EnableDebug', true);
        }
    }
    private function CountConnectedChildren(): int
    {
        $children = IPS_GetInstanceListByModuleID('{A2D316A2-E403-4B88-A223-E2E60C6AE1FA}');
        $count = 0;
        foreach ($children as $cid) {
            $c = IPS_GetInstance($cid);
            if ((int)$c['ConnectionID'] === $this->InstanceID) {
                $count++;
            }
        }
        return $count;
    }

}
