<?php declare(strict_types=1);

if (!defined('IPS_KERNELMESSAGE')) {
    define('IPS_KERNELMESSAGE', 10100);
}
if (!defined('KR_READY')) {
    define('KR_READY', 10103);
}

class WebHookModule extends IPSModule
{
    private string $hook = '';

    public function __construct($InstanceID, string $hook)
    {
        parent::__construct($InstanceID);
        $this->hook = $hook;
    }

    public function Create(): void
    {
        parent::Create();
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELMESSAGE && isset($Data[0]) && $Data[0] == KR_READY) {
            $this->RegisterHookInternal('/hook/' . $this->hook);
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->RegisterHookInternal('/hook/' . $this->hook);
        }
    }

    private function RegisterHookInternal(string $WebHook): void
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) === 0) {
            return;
        }

        $hooks = json_decode((string)IPS_GetProperty($ids[0], 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }

        $found = false;
        foreach ($hooks as $index => $hook) {
            if (!is_array($hook)) {
                continue;
            }
            if (($hook['Hook'] ?? '') === $WebHook) {
                if ((int)($hook['TargetID'] ?? 0) === $this->InstanceID) {
                    return;
                }
                $hooks[$index]['TargetID'] = $this->InstanceID;
                $found = true;
            }
        }

        if (!$found) {
            $hooks[] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
        }

        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }
}
