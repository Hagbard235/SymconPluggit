<?php
trait PluggitContinuousFour
{
    private function CreateContinuousFour(): void
    {
        $this->RegisterAttributeInteger('ContinuousFourPreviousLevel', 3);
        $this->RegisterAttributeInteger('ContinuousFourPreviousMode', 1);
        $this->RegisterTimer('ContinuousFourCheck', 0, 'PLUG_CheckContinuousFour($_IPS["TARGET"]);');
    }
    private function ApplyContinuousFour(): void
    {
        $this->RegisterVariableBoolean('ContinuousFour', 'Dauerhaft Stufe 4', '~Switch', 100);
        $this->EnableAction('ContinuousFour');
        $this->RegisterVariableString('ContinuousFourStatus', 'Dauerbetrieb Rückmeldung', '', 101);
        $active = $this->GetValue('ContinuousFour');
        $this->SetTimerInterval('ContinuousFourCheck', $active ? 15000 : 0);
        if ($active) $this->SetTimerInterval('ResetFanSpeedLevel', 0);
    }
    public function SetContinuousFour(bool $active): void
    {
        $lock = 'PLUG_HOLD_'.$this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 0)) throw new RuntimeException('KWL wird gerade geprüft. Bitte erneut versuchen.');
        try {
            if ($active === $this->GetValue('ContinuousFour')) return;
            if ($active) {
                $level = $this->GetFanSpeedLevel();
                $mode = $this->GetOperatingState();
                $alarm = $this->GetAlarmState();
                if (!is_numeric($level) || $level < 0 || $level > 4 || !in_array($mode, [1,2,3,5,6,16], true) || $alarm !== 0)
                    throw new RuntimeException('Dauerbetrieb nicht gestartet: Gerätezustand unklar, Störung oder geschützter Sonderbetrieb.');
                $this->WriteAttributeInteger('ContinuousFourPreviousLevel', (int)$level);
                $this->WriteAttributeInteger('ContinuousFourPreviousMode', $mode);
                $this->SetValue('ContinuousFour', true);
                $this->SetTimerInterval('ResetFanSpeedLevel', 0);
                $this->SetTimerInterval('ContinuousFourCheck', 15000);
                $this->SetValue('ContinuousFourStatus', 'Aktiviert; Stufe 4 wird angefordert.');
            } else {
                $this->SetValue('ContinuousFour', false);
                $this->SetTimerInterval('ContinuousFourCheck', 0);
                $this->SetTimerInterval('ResetFanSpeedLevel', 0);
                $mode = $this->GetOperatingState();
                $alarm = $this->GetAlarmState();
                if ($alarm !== 0 || !in_array($mode, [1,2,3,5,6,16], true)) {
                    $this->SetValue('ContinuousFourStatus', 'Aus; wegen Störung oder Sonderbetrieb keine Rückstellung am Gerät.');
                    return;
                }
                $this->SetFanSpeedLevel($this->ReadAttributeInteger('ContinuousFourPreviousLevel'));
                $previousMode = $this->ReadAttributeInteger('ContinuousFourPreviousMode');
                if ($previousMode !== 1) $this->SetOperatingState($previousMode);
                $level = $this->GetFanSpeedLevel();
                $mode = $this->GetOperatingState();
                if ($level === null || (int)$level !== $this->ReadAttributeInteger('ContinuousFourPreviousLevel') || $mode !== $previousMode)
                    throw new RuntimeException('Dauerbetrieb aus; Rückstellung noch nicht vom Gerät bestätigt.');
                $this->SetValue('prmRomIdxSpeedLevel', (int)$level);
                $this->SetValue('prmRamIdxUnitMode', $mode);
                $this->SetValue('ContinuousFourStatus', 'Aus; vorherige Stufe und Betriebsart bestätigt. Normale Steuerung wieder freigegeben.');
            }
        } catch (Throwable $e) {
            $this->SetValue('ContinuousFourStatus', 'Fehler: '.$e->getMessage());
            throw $e;
        } finally { IPS_SemaphoreLeave($lock); }
        if ($active) $this->CheckContinuousFour();
    }
    public function CheckContinuousFour(): void
    {
        if (!$this->GetValue('ContinuousFour')) return;
        $lock = 'PLUG_HOLD_'.$this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 0)) return;
        try {
            if (!$this->GetValue('ContinuousFour')) return;
            $alarm = $this->GetAlarmState();
            $mode = $this->GetOperatingState();
            if ($alarm !== 0 || !in_array($mode, [1,2,3,5,6,16], true)) {
                $this->SetValue('ContinuousFourStatus', 'Aktiv, aber pausiert: Störung oder geschützter Sonderbetrieb (z. B. Kamin).');
                return;
            }
            $level = $this->GetFanSpeedLevel();
            if (!is_numeric($level) || $level < 0 || $level > 4) throw new RuntimeException('Ungültige Rückmeldung der Lüfterstufe');
            $this->SetValue('prmRomIdxSpeedLevel', (int)$level);
            $this->SetValue('prmRamIdxUnitMode', $mode);
            if ((int)$level !== 4 || $mode !== 1) {
                $this->SetFanSpeedLevel(4);
                $level = $this->GetFanSpeedLevel();
                $mode = $this->GetOperatingState();
                if ((int)$level !== 4 || $level === null || $mode !== 1) throw new RuntimeException('Stufe 4 noch nicht vom Gerät bestätigt');
                $this->SetValue('prmRomIdxSpeedLevel', 4);
                $this->SetValue('prmRamIdxUnitMode', 1);
            }
            $this->SetValue('ContinuousFourStatus', 'Aktiv: Stufe 4 am Gerät bestätigt. Prüfung alle 15 Sekunden.');
        } catch (Throwable $e) {
            $this->SetValue('ContinuousFourStatus', 'Aktiv; Prüfung fehlgeschlagen, nächster Versuch in 15 Sekunden: '.$e->getMessage());
        } finally { IPS_SemaphoreLeave($lock); }
    }
}
