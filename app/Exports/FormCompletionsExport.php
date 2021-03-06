<?php

namespace App\Exports;

use App\Models\Form;
use App\Models\FormCompletion;
use Exception;
use Maatwebsite\Excel\Concerns\FromArray;

class FormCompletionsExport implements FromArray
{
    protected Form $formData;

    public function __construct(Form $formData)
    {
        $this->formData = $formData;
    }

    public function array(): array
    {
        //form name
        //question headers
        //completion 1
        //completion 2
        //...

        //works
        //$headerRow = ["","a", "b", "c"];
        //$completionRows = [[1,2,3], [4,5,6], [1,3,5]];

        $formName = [$this->formData->name]; //sheet header
        $headerRow = ["Completion ID"]; //corner value
        $completionRows = [];

        $correspondingCompletionsIds = [];
        foreach (FormCompletion::where(['form_id' => $this->formData->id])->get() as $item) {
            array_push($correspondingCompletionsIds, $item->id);
            $completionRows[$item->id] = [$item->id];
        }

        foreach ($this->formData->formElements as $element) {
            $answers = [];
            $notAnsweredYet = $correspondingCompletionsIds;
            $inputElement = $element->inputElement;
            if ($inputElement) {
                if ($inputElement->booleanInput) {
                    $answers = $inputElement->booleanInput->booleanInputAnswers;
                } else if ($inputElement->dateInput) {
                    $answers = $inputElement->dateInput->dateInputAnswers;
                } else if ($inputElement->textInput) {
                    $answers = $inputElement->textInput->textInputAnswers;
                } else if ($inputElement->numberInput) {
                    $answers = $inputElement->numberInput->numberInputAnswers;
                } else if ($inputElement->selectInput) {
                    $answers = $inputElement->selectInput->selectInputChoices;
                    $hasHiddenLabel = $inputElement->selectInput->has_hidden_label;

                    $arrById = [];
                    foreach ($correspondingCompletionsIds as $id) {
                        $arrById[$id] = [];
                    }

                    foreach ($answers as $answer) {
                        $text = null;
                        if ($hasHiddenLabel) $text = $answer->hidden_label;
                        else $text = $answer->text;

                        foreach ($answer->selectInputAnswers as $item) {
                            array_push($arrById[$item->form_completion_id], $text);
                        }
                    }
                    
                    foreach ($arrById as $key => $value) {
                        if (in_array($key, $notAnsweredYet)) {
                            if (($thisKey = array_search($key, $notAnsweredYet)) !== false) unset($notAnsweredYet[$thisKey]);
                            array_push($completionRows[$key], implode(";", $value));
                        }
                        else throw new Exception("Internal sheet creation error (values doesn't match)");
                    }

                    if (count($notAnsweredYet) > 0) throw new Exception("Internal sheet creation error (not all of required select values were filled)");

                    $mandatoryPart = "";
                    if ($inputElement->is_mandatory == true) $mandatoryPart = " *";
                    array_push($headerRow, $inputElement->header . $mandatoryPart);
                    continue;
                }
                foreach ($answers as $answer) {
                    if (in_array($answer->form_completion_id, $notAnsweredYet)) {
                        if (($key = array_search($answer->form_completion_id, $notAnsweredYet)) !== false) unset($notAnsweredYet[$key]);

                        if ($answer->value === false) array_push($completionRows[$answer->form_completion_id], "false");
                        else if ($answer->value === true) array_push($completionRows[$answer->form_completion_id], "true");
                        else array_push($completionRows[$answer->form_completion_id], $answer->value);
                    } else throw new Exception("Internal sheet creation error (values doesn't match)");
                }

                if (count($notAnsweredYet) > 0) throw new Exception("Internal sheet creation error (not all of required select values were filled)");

                $mandatoryPart = "";
                if ($inputElement->is_mandatory == true) $mandatoryPart = "*";
                array_push($headerRow, $inputElement->header . $mandatoryPart);
            }
        }

        return [$formName, $headerRow, $completionRows];
    }
}
