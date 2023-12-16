<?php

declare(strict_types=1);
	class IPSDimmer extends IPSModule
	{
	    private const MODULE_PREFIX = 'DIM';
	    
		public function Create()
		{
			//Never delete this line!
			parent::Create();
			
			##### Target
			$this->RegisterPropertyInteger('TargetVariable', 0);
			
			$this->RegisterPropertyInteger('TargetBrightness', 0);
			
			$this->RegisterPropertyInteger('TargetColor', 0);
			
			##### Input Switch variable
			$id = @$this->GetIDForIdent('DIMStatus');
            $id = $this->RegisterVariableBoolean('DIMStatus', 'Status', '~Switch', 10);
			$this->EnableAction('DIMStatus');
			if (!$id) {
                $this->SetValue('DIMStatus', true);
            }
			
			##### Einstellungen
			
			$this->RegisterPropertyInteger('DimSpeedan', 30);
			$this->RegisterPropertyInteger('DimSpeedaus', 5);
			
			$this->RegisterPropertyInteger('EndColor', 500);
			
			$this->RegisterPropertyInteger('EndIntensity', 255);
			
			$script = self::MODULE_PREFIX .'_' . 'Timer(' . $this->InstanceID . ');';
		    $this->RegisterTimer('DimTimer',0,$script);

            $this->RegisterAttributeFloat('DimmingCurrent',0);
			$this->RegisterAttributeFloat('DimmingStep',1);
			$this->RegisterAttributeFloat('DimmingEnd',255);

            $this->RegisterAttributeFloat('DimmingColorCurrent',556);
			$this->RegisterAttributeFloat('DimmingColorStep',-1);
			$this->RegisterAttributeFloat('DimmingColorEnd',155);
		}

		public function Destroy()
		{
			//Never delete this line!
			parent::Destroy();
		}

		public function ApplyChanges()
		{
		    //Wait until IP-Symcon is started
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
        
			//Never delete this line!
			parent::ApplyChanges();
			
			//Check runlevel
            if (IPS_GetKernelRunlevel() != KR_READY) {
                return;
            }
    
            //Delete all references
            foreach ($this->GetReferenceList() as $referenceID) {
                $this->UnregisterReference($referenceID);
            }
    
            //Delete all update messages
            foreach ($this->GetMessageList() as $senderID => $messages) {
                foreach ($messages as $message) {
                    if ($message == VM_UPDATE) {
                        $this->UnregisterMessage($senderID, VM_UPDATE);
                    }
                }
            }
            
            $current = false;
            $targetState = $this->ReadPropertyInteger('TargetVariable');
            if ($targetState != 0 && @IPS_ObjectExists($targetState)) 
            {
                $current = GetValueBoolean($targetState);
            }
            
            $id = @$this->GetIDForIdent('DIMStatus');
            if ($id != 0 && @IPS_ObjectExists($id)) {
                $this->RegisterReference($id);
                SetValueBoolean($id,$current);
                $this->RegisterMessage($id, VM_UPDATE);
            }
            
            //Reset buffer
            $this->SetBuffer('LastMessage', json_encode([]));

		}
		
		public function RequestAction($Ident, $Value)
        {
            switch ($Ident) {
    
                case 'DIMStatus':
                    $this->SetValue($Ident, $Value);
                    break;
    
                
            }
        }
		
		public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
        {
            $this->SendDebug('MessageSink', 'Message from SenderID ' . $SenderID . ' with Message ' . $Message . "\r\n Data: " . print_r($Data, true), 0);
            if (!empty($Data)) {
                foreach ($Data as $key => $value) {
                    $this->SendDebug(__FUNCTION__, 'Data[' . $key . '] = ' . json_encode($value), 0);
                }
            }
    
            if (json_decode($this->GetBuffer('LastMessage'), true) === [$SenderID, $Message, $Data]) {
                $this->SendDebug(__FUNCTION__, sprintf(
                    'Doppelte Nachricht: Timestamp: %s, SenderID: %s, Message: %s, Data: %s',
                    $TimeStamp,
                    $SenderID,
                    $Message,
                    json_encode($Data)
                ), 0);
                return;
            }
    
            $this->SetBuffer('LastMessage', json_encode([$SenderID, $Message, $Data]));
    
            switch ($Message) {
                case IPS_KERNELSTARTED:
                    $this->KernelReady();
                    break;
    
                case EM_UPDATE:
                   
                    break;
    
                case VM_UPDATE:
    
                    if ($SenderID == @$this->GetIDForIdent('DIMStatus') && $Data[1]) { // only on change

    					$this->RunDimmer($Data[0]);
                    }   
                    
                   
                    break;
    
            }
        }
        private function RunDimmer($targetState)
        {
            
            $timeslice = 500; //500ms Timer
            
            $targetVariable = $this->ReadPropertyInteger('TargetVariable');
            if ($targetVariable != 0 && @IPS_ObjectExists($targetVariable)) 
            {
                $current = GetValueBoolean($targetVariable);
                
                if ($current != $targetState)
                {
                    //es findet eine Änderung des Zustands statt
                   
                    if ($targetState == TRUE)
                    {
                        $this->SendDebug("Dimmer", "Dimme nach an", 0);
                        $this->SetTimerInterval("DimTimer", 0);
                        
                        $startIntensity = 0;
                        $targetBrightness = $this->ReadPropertyInteger('TargetBrightness');
                        if ($targetBrightness != 0 && @IPS_ObjectExists($targetBrightness)) 
                        {
                            @RequestAction($targetBrightness, $startIntensity);
                        }
                       
                        
                        $startColor = 556;//ganz warm = start
                        $targetColor = $this->ReadPropertyInteger('TargetColor');
                        if ($targetColor != 0 && @IPS_ObjectExists($targetColor)) 
                        {
                            @RequestAction($targetColor, $startColor); 
                        }
                        
                        
                        @RequestAction($targetVariable, $targetState);
                        
                        $endIntensity = $this->ReadPropertyInteger('EndIntensity');
                        $endColor = $this->ReadPropertyInteger('EndColor');
                        $duration = $this->ReadPropertyInteger('DimSpeedan') * 1000.0;
                        
                        $steps = $duration / $timeslice;
                        $step = ($endIntensity-$startIntensity)/$steps;
                        
                        $stepColor = ($endColor-$startColor)/$steps;
                        
				        $this->SendDebug('Dimmer', 'Dimme ' . $startIntensity . ' ' . $step . ' ->' . $endIntensity, 0);
				        
				        $this->WriteAttributeFloat('DimmingCurrent',$startIntensity);
				        $this->WriteAttributeFloat('DimmingStep',$step);
				        $this->WriteAttributeFloat('DimmingEnd',$endIntensity);
				        
				        $this->SendDebug('Dimmer', 'Dimme Farbe von ' . $startColor . ' ' . $stepColor . ' nach ' . $endColor, 0);
				        $this->WriteAttributeFloat('DimmingColorCurrent',$startColor);
				        $this->WriteAttributeFloat('DimmingColorStep',$stepColor);
				        $this->WriteAttributeFloat('DimmingColorEnd',$endColor);
				        
				        $this->SetTimerInterval("DimTimer", $timeslice);
                    }
                    else
                    {
                        $this->SendDebug("Dimmer", "Dimme nach aus", 0);
                        $this->SetTimerInterval("DimTimer", 0);
                        
                        $targetBrightness = $this->ReadPropertyInteger('TargetBrightness');
                        if ($targetBrightness != 0 && @IPS_ObjectExists($targetBrightness)) 
                        {
                            $start = GetValueInteger($targetBrightness);

                        }
                        
                        $targetColor = $this->ReadPropertyInteger('TargetColor');
                        if ($targetColor != 0 && @IPS_ObjectExists($targetColor)) 
                        {
                            $startColor = GetValueInteger($targetColor);
                        }
                        
                        $end = 0;
                        $endColor = $startColor;
                        $stepColor=0;
                        $duration = $this->ReadPropertyInteger('DimSpeedaus') * 1000.0;
                        
                        $steps = $duration / $timeslice;
                        $step = ($end-$start)/$steps;
                        $this->SendDebug('Dimmer', 'Dimme von ' . $start . ' ' . $step . ' nach ' . $end, 0);
                        
                        $this->WriteAttributeFloat('DimmingCurrent',$start);
				        $this->WriteAttributeFloat('DimmingStep',$step);
				        $this->WriteAttributeFloat('DimmingEnd',$end);
				        
				        $this->SendDebug('Dimmer', 'Dimme Farbe von ' . $startColor . ' ' . $stepColor . ' nach ' . $endColor, 0);
				        $this->WriteAttributeFloat('DimmingColorCurrent',$startColor);
				        $this->WriteAttributeFloat('DimmingColorStep',$stepColor);
				        $this->WriteAttributeFloat('DimmingColorEnd',$endColor);
				        
				        $this->SetTimerInterval("DimTimer", $timeslice);
                        
                    }
                    
                    
                }
            }
            
        }
        public function Timer():void
        {
            $current = $this->ReadAttributeFloat('DimmingCurrent');
            $step = $this->ReadAttributeFloat('DimmingStep');
            $end = $this->ReadAttributeFloat('DimmingEnd');
            
            $currentColor = $this->ReadAttributeFloat('DimmingColorCurrent');
            $stepColor = $this->ReadAttributeFloat('DimmingColorStep');
            $endColor = $this->ReadAttributeFloat('DimmingColorEnd');
            
            $current = $current + $step;
            $this->SendDebug('Dimmer', 'Dimme ' . $current . ' ->' . $end, 0);
            
            $currentColor = $currentColor + $stepColor;
            $this->SendDebug('Dimmer', 'Dimme Farbe' . $currentColor . ' ->' . $endColor, 0);
            
            if (($step > 0) && ($current >= $end))
            {
                $this->SetTimerInterval("DimTimer", 0); //timer off
                $current = $end;
            }
            
            if (($step < 0) && ($current <= $end))
            {
                $this->SetTimerInterval("DimTimer", 0); //timer off
                $current = $end;
                $targetVariable = $this->ReadPropertyInteger('TargetVariable');
                if ($targetVariable != 0 && @IPS_ObjectExists($targetVariable)) 
                {
                     @RequestAction($targetVariable, false);
                }
            }
            
            $targetBrightness = $this->ReadPropertyInteger('TargetBrightness');
            if ($targetBrightness != 0 && @IPS_ObjectExists($targetBrightness)) 
            {
                @RequestAction($targetBrightness, $current);
            }
            
            $targetColor = $this->ReadPropertyInteger('TargetColor');
            if ($targetColor != 0 && @IPS_ObjectExists($targetColor)) 
            {
                @RequestAction($targetColor, $currentColor);
            }
            
            $this->WriteAttributeFloat('DimmingCurrent',$current);
            $this->WriteAttributeFloat('DimmingColorCurrent',$currentColor);
            
        }
        
	}