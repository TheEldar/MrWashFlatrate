<?php declare(strict_types=1);

class MrWashSplitter extends IPSModule
{
    private const IFACE_TO_DEVICE = '{927CEF32-FF05-4518-95CD-1F5709CF7FA2}';
    private const IFACE_TO_IO = '{F4B171C0-030A-4B6A-9415-8BBF6949380D}';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyBoolean('EnableDebug', true);
}

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $parentID = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($parentID === 0) {
            $this->SetStatus(201);  // kein IO verbunden
            return;
        }

        $this->SetStatus(102);
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

        // Hier wird alles vom Device 1:1 nach oben an die IO weitergereicht
        $this->Dbg('ForwardUp', 'To parent DataID ' . self::IFACE_TO_IO);

        $this->SendDataToParent(json_encode([
            'DataID' => self::IFACE_TO_IO,
            'Buffer' => (string)$data->Buffer
        ]));

        return 'OK';
    }


    private function IsDebugEnabled(): bool
    {
        return $this->ReadPropertyBoolean('EnableDebug');
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
