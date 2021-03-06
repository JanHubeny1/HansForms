<?php

namespace App\Http\Controllers;

use App\Mail\FormInvitation;
use App\Models\BooleanInput;
use App\Models\DateInput;
use App\Models\FormElement;
use App\Models\FormPrivateAccessToken;
use App\Models\InputElement;
use App\Models\NewPage;
use App\Models\NumberInput;
use App\Models\SelectInput;
use App\Models\SelectInputChoice;
use App\Models\TextInput;
use DateTime;
use Exception;
use Illuminate\Http\Request;
use App\Models\Form;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

use function PHPUnit\Framework\returnSelf;

class FormController extends Controller
{
    private function validateDate($date, $format = 'Y-m-d H:i:s')
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) == $date;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $userId = Auth::user()->id;
        if ($userId) {
            return Form::where('user_id', $userId)
                ->without('formElements', 'user')
                ->orderBy('created_at', 'desc')
                ->get();
        } else return response("Unauthorized", 401);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $updateSlug = null, $updateHasPrivateToken = null)
    {
        $userId = -1;
        if (!Auth::user()) return response("Unauthorized - log in to create forms...", 401);
        else $userId = Auth::user()->id;

        $formProps = [];
        $formProps['header'] = null;
        $formProps['description'] = null;

        $formProps['start_time'] = null;
        $formProps['end_time'] = null;

        //basic information validation
        if (!$request->all()) return response("Invalid data (expected data).", 400);

        if (array_key_exists('start_time', $request->all()) && array_key_exists('end_time', $request->all())) {
            $startDate = str_replace("T", " ", $request->all()['start_time']);
            $endDate = str_replace("T", " ", $request->all()['end_time']);

            if (
                ($this->validateDate($startDate, 'Y-m-d H:i') || $this->validateDate($startDate)) &&
                ($this->validateDate($endDate, 'Y-m-d H:i') || $this->validateDate($endDate))
            ) {
                if (new DateTime($request->all()['start_time']) < new DateTime($request->all()['end_time'])) {
                    $currentTime = time();
                    $formEndTime = strtotime($endDate);
                    if ($currentTime > $formEndTime) {
                        return response("Invalid data (creating expired form is not allowed).", 400);
                    }

                    $formProps['start_time'] = new DateTime($request->all()['start_time']);
                    $formProps['end_time'] = new DateTime($request->all()['end_time']);
                } else return response("Invalid data (start is higher than end).", 400);
            } else {
                return response("Invalid data (invalid start/end date).", 400);
            }
        } else return response("Invalid data (missing start/end date).", 400);

        $formProps['has_private_token'] = false;
        $formProps['private_emails'] = [];

        if (array_key_exists('has_private_token', $request->all())) {
            if (is_bool($request->all()['has_private_token']) && $request->all()['has_private_token']) $formProps['has_private_token'] = true;
        }

        if ($formProps['has_private_token']) {
            if (array_key_exists('private_emails', $request->all())) {
                //basic input mode
                if (is_array($request->all()['private_emails']) && count($request->all()['private_emails']) > 0) {
                    //emails validation
                    $validator = Validator::make($request->all(), [
                        'private_emails.*.email' => 'email|required|regex:/(.+)@(.+)\.(.+)/i',
                    ]);

                    if ($validator->fails()) {
                        return response("Invalid data (invalid private emails - basic input mode).", 400);
                    } else {
                        $formProps['private_emails'] = array_map(function ($validatorEmail) {
                            return $validatorEmail['email'];
                        }, $validator->validated()['private_emails']);
                    }
                } //raw text input mode
                else if ($request->all()['private_emails']) {
                    $dataToValidate['emails'] = explode("\n", $request->all()['private_emails']);

                    $validator = Validator::make($dataToValidate, [
                        'emails.*' => 'email|required|regex:/(.+)@(.+)\.(.+)/i',
                    ]);

                    if ($validator->fails()) {
                        return response("Invalid data (invalid private emails - raw text input mode).", 400);
                    } else {
                        $formProps['private_emails'] = $dataToValidate['emails'];
                    }
                } else {
                    return response("Invalid data (missing array or formatted string of private emails).", 400);
                }
            } else return response("Invalid data (missing array of private emails).", 400);
        }

        if (!array_key_exists('items', $request->all()))
            return response("Invalid data (missing array of questions).", 400);

        if (!is_array($request->all()['items']))
            return response("Invalid data (expected array of questions).", 400);

        if (count($request->all()['items']) < 1)
            return response("Invalid data (expected non-empty array of questions).", 400);

        if (array_key_exists('header', $request->all())) {
            if (strval($request->all()['header']) !== "") {
                $formProps['header'] = strval($request->all()['header']);
            } else return response("Invalid data (expected non-empty header of form).", 400);
        } else return response("Invalid data (missing header of form).", 400);

        if (array_key_exists('description', $request->all())) {
            if (strval($request->all()['description']) !== "") {
                $formProps['description'] = strval($request->all()['description']);
            }
        }

        $questionOrder = 0;
        $validatedQuestions = [];
        $atLeastOneMandatory = false;

        //questions validation (+ order validation)
        foreach ($request->all()['items'] as $item) {
            $validatedQuestion = [];
            //main props: header, is_mandatory, order, type
            try {
                if (!$item) return response("Invalid data (invalid item).", 400);

                if (array_key_exists('type', $item)) {
                    if (!is_string($item['type'])) return response("Invalid type value type.", 400);

                    if ($item['type'] !== "new_page") {
                        if (
                            !array_key_exists('header', $item) ||
                            !array_key_exists('is_mandatory', $item)
                        ) return response("Invalid data (missing required question values).", 400);

                        if (!is_string($item['header'])) return response("Invalid header value type.", 400);

                        if (!is_bool($item['is_mandatory'])) return response("Invalid is_mandatory value type.", 400);
                    }

                    if (array_key_exists('order', $item)) {
                        if (!is_int($item['order'])) return response("Invalid order value type.", 400);

                        if ($item['order'] !== $questionOrder) {
                            return response("Invalid order value.", 400);
                        } else $questionOrder++;
                    } else return response("Invalid data (missing order value).", 400);

                    if ($item['type'] === "new_page") {
                        $newPage = ['type' => "new_page", "order" => $item['order']];
                        $validatedQuestions[] = $newPage;
                        continue;
                    }
                } else return response("Invalid data (missing valid type of item).", 400);

                //validated
                $validatedQuestion['type'] = $item['type'];
                $validatedQuestion['header'] = $item['header'];
                $validatedQuestion['is_mandatory'] = $item['is_mandatory'];
                $validatedQuestion['order'] = $item['order'];

                if ($validatedQuestion['is_mandatory']) {
                    $atLeastOneMandatory = true;
                }
            } catch (Exception $exception) {
                return response($exception, 400);
                //return response("Unhandled input error (in basic values).", 400);
            }
            //check type
            switch ($item['type']) {
                case "text":
                {
                    //props: min_length, max_length, strict_length
                    try {
                        $min = null;
                        $max = null;
                        $strict = null;

                        $validatedQuestion['min_length'] = $min;
                        $validatedQuestion['max_length'] = $max;
                        $validatedQuestion['strict_length'] = $strict;

                        if (array_key_exists('min_length', $item)) {
                            if (
                                intval($item['min_length']) &&
                                intval($item['min_length']) > 0
                            ) $min = intval($item['min_length']);
                        }
                        if (array_key_exists('max_length', $item)) {
                            if (
                                intval($item['max_length']) &&
                                intval($item['max_length']) > 0
                            ) $max = intval($item['max_length']);
                        }
                        if (array_key_exists('strict_length', $item)) {
                            if (
                                intval($item['strict_length']) &&
                                intval($item['strict_length']) > 0
                            ) $strict = intval($item['strict_length']);
                        }

                        if ($strict) {
                            $validatedQuestion['strict_length'] = $strict;
                        } else if ($max && $min) {
                            if ($min < $max) {
                                $validatedQuestion['min_length'] = $min;
                                $validatedQuestion['max_length'] = $max;
                            }
                        } else if ($min) $validatedQuestion['min_length'] = $min;
                        else if ($max) $validatedQuestion['max_length'] = $max;
                    } catch (Exception $exception) {
                        return response("Unhandled input error (in type-specific values). ({$exception->getMessage()})", 400);
                    }
                    break;
                }
                case "date":
                {
                    try {
                        //props: min, max
                        $min = null;
                        $max = null;

                        $validatedQuestion['min'] = $min;
                        $validatedQuestion['max'] = $max;

                        if (array_key_exists('min', $item)) {
                            if ($this->validateDate($item['min'], 'Y-m-d')) $min = $item['min'];
                        }

                        if (array_key_exists('max', $item)) {
                            if ($this->validateDate($item['max'], 'Y-m-d')) $max = $item['max'];
                        }

                        if ($max && $min) {
                            if (new DateTime($min) < new DateTime($max)) {
                                $validatedQuestion['min'] = $min;
                                $validatedQuestion['max'] = $max;
                            }
                        } else if ($max) $validatedQuestion['max'] = $max;
                        else if ($min) $validatedQuestion['min'] = $min;
                    } catch (Exception $exception) {
                        return response("Unhandled input error (in type-specific values). ({$exception->getMessage()})", 400);
                    }
                    break;
                }
                case "boolean":
                    break;
                case "number":
                {
                    //props: min, max, can_be_decimal
                    try {
                        $min = null;
                        $max = null;
                        $can_be_decimal = false;

                        $validatedQuestion['min'] = $min;
                        $validatedQuestion['max'] = $max;
                        $validatedQuestion['can_be_decimal'] = $can_be_decimal;

                        if (array_key_exists('min', $item)) {
                            if (floatval($item['min'])) $min = floatval($item['min']);
                        }
                        if (array_key_exists('max', $item)) {
                            if (floatval($item['max'])) $max = floatval($item['max']);
                        }
                        if (array_key_exists('can_be_decimal', $item)) {
                            if (is_bool($item['can_be_decimal'])) $can_be_decimal = $item['can_be_decimal'];
                        }

                        if ($max && $min) {
                            if ($min < $max) {
                                $validatedQuestion['min'] = $min;
                                $validatedQuestion['max'] = $max;
                            }
                        } else if ($min) $validatedQuestion['min'] = $min;
                        else if ($max) $validatedQuestion['max'] = $max;
                        $validatedQuestion['can_be_decimal'] = $can_be_decimal;
                    } catch (Exception $exception) {
                        return response("Unhandled input error (in type-specific values). ({$exception->getMessage()})", 400);
                    }
                    break;
                }
                case "select":
                {
                    try {
                        $is_multiselect = false;
                        $min_amount_of_answers = null;
                        $max_amount_of_answers = null;
                        $strict_amount_of_answers = null;
                        $has_hidden_label = false;
                        $choices = [];
                        $choicesCount = -1;

                        $validatedQuestion['is_multiselect'] = $is_multiselect;
                        $validatedQuestion['min_amount_of_answers'] = $min_amount_of_answers;
                        $validatedQuestion['max_amount_of_answers'] = $max_amount_of_answers;
                        $validatedQuestion['strict_amount_of_answers'] = $strict_amount_of_answers;
                        $validatedQuestion['has_hidden_label'] = $has_hidden_label;


                        if (array_key_exists('choices', $item)) {
                            if (!is_array($item['choices'])) {
                                return response("Invalid data for select question (expected array of choices).", 400);
                            }
                            if (count($item['choices']) >= 2) {
                                $choicesCount = count($item['choices']);
                                if (array_key_exists('has_hidden_label', $item)) {
                                    if (is_bool($item["has_hidden_label"])) $has_hidden_label = $item["has_hidden_label"];
                                } else return response("Invalid data (missing has_hidden_label value).", 400);

                                $choiceOrder = 0;
                                $uniqueHiddenLabels = [];
                                foreach ($item['choices'] as $choice) {
                                    $hidden_label = null;
                                    $text = null;
                                    $thisChoiceOrder = null;
                                    //order check
                                    if (array_key_exists('order', $choice)) {
                                        if (intval($choice['order']) !== $choiceOrder) {
                                            return response("Invalid data (order is not valid).", 400);
                                        } else {
                                            $thisChoiceOrder = $choiceOrder;
                                            $choiceOrder++;
                                        }
                                    } else return response("Invalid data (order is missing).", 400);
                                    //hidden label check
                                    if ($has_hidden_label) {
                                        if (array_key_exists('hidden_label', $choice)) {
                                            if ($choice['hidden_label'] === "0") {
                                                $hidden_label = 0;
                                                if (in_array($hidden_label, $uniqueHiddenLabels)) {
                                                    return response("Invalid data (hidden_label is not unique).", 400);
                                                } else array_push($uniqueHiddenLabels, $hidden_label);
                                            } else if (intval($choice['hidden_label'])) {
                                                $hidden_label = intval($choice['hidden_label']);
                                                if (in_array($hidden_label, $uniqueHiddenLabels)) {
                                                    return response("Invalid data (hidden_label is not unique).", 400);
                                                } else array_push($uniqueHiddenLabels, $hidden_label);
                                            } else return response("Invalid data (invalid hidden_label type in choice).", 400);
                                        } else return response("Invalid data (missing hidden_label in choice).", 400);
                                    }
                                    //text check
                                    if (array_key_exists('text', $choice)) {
                                        if ($choice['text'] === "0") $text = $choice['text'];
                                        else if (mb_strlen($choice['text']) && !is_null($choice['text'])) $text = strval($choice['text']);
                                        else return response("Invalid data (empty text in choice).", 400);
                                    } else return response("Invalid data (text is missing).", 400);

                                    $choices[] = ['text' => $text, "hidden_label" => $hidden_label, "order" => $thisChoiceOrder];
                                }
                            } else return response("Invalid amount of choices for select question (there should be at least two).", 400);
                        } else return response("Invalid data for select question (choices are missing).", 400);

                        if (array_key_exists('is_multiselect', $item)) {
                            if (is_bool($item['is_multiselect'])) $is_multiselect = $item['is_multiselect'];

                            if (array_key_exists('min_amount_of_answers', $item)) {
                                if (
                                    intval($item['min_amount_of_answers']) &&
                                    intval($item['min_amount_of_answers']) >= 0 &&
                                    intval($item['min_amount_of_answers']) < $choicesCount
                                ) $min_amount_of_answers = intval($item['min_amount_of_answers']);
                            }
                            if (array_key_exists('max_amount_of_answers', $item)) {
                                if (
                                    intval($item['max_amount_of_answers']) &&
                                    intval($item['max_amount_of_answers']) > 0 &&
                                    intval($item['max_amount_of_answers']) <= $choicesCount
                                ) $max_amount_of_answers = intval($item['max_amount_of_answers']);
                            }
                            if (array_key_exists('strict_amount_of_answers', $item)) {
                                if (
                                    intval($item['strict_amount_of_answers']) &&
                                    intval($item['strict_amount_of_answers']) > 0 &&
                                    intval($item['strict_amount_of_answers']) <= $choicesCount
                                ) $strict_amount_of_answers = intval($item['strict_amount_of_answers']);
                            }
                        }


                        $validatedQuestion['is_multiselect'] = $is_multiselect;
                        if ($is_multiselect) {
                            if ($strict_amount_of_answers) {
                                $validatedQuestion['strict_amount_of_answers'] = $strict_amount_of_answers;
                            } else {
                                if ($max_amount_of_answers && $min_amount_of_answers) {
                                    if ($min_amount_of_answers < $max_amount_of_answers) {
                                        $validatedQuestion['min_amount_of_answers'] = $min_amount_of_answers;
                                        $validatedQuestion['max_amount_of_answers'] = $max_amount_of_answers;
                                    }
                                } else if ($min_amount_of_answers) $validatedQuestion['min_amount_of_answers'] = $min_amount_of_answers;
                                else if ($max_amount_of_answers) $validatedQuestion['max_amount_of_answers'] = $max_amount_of_answers;
                            }
                        }

                        $validatedQuestion['has_hidden_label'] = $has_hidden_label;
                        $validatedQuestion['choices'] = $choices;
                    } catch (Exception $exception) {
                        return response("Unhandled input error (in type-specific values). ({$exception->getMessage()})", 400);
                    }

                    break;
                }
                default:
                    return response("Invalid question type.", 400);
            }
            $validatedQuestions[] = $validatedQuestion;
        }

        //at least one mandatory validation
        if (!$atLeastOneMandatory) return response("Invalid form (expected at least one mandatory question).", 400);

        //new pages validation
        //*no new page at start
        //*no 2 or more new pages together
        //*no new page at end
        //*right format: q,q,np,q,np,q,q,q,q,np
        $validatedElements = array_map(function ($x) {
            if ($x['type'] === 'new_page') return 0;
            else return 1;
        }, $validatedQuestions);

        if ($validatedElements[0] === 0) return response("Invalid form (new page element on the start is not allowed).", 400);
        if ($validatedElements[count($validatedElements) - 1] === 0) return response("Invalid form (new page element on the end is not allowed).", 400);

        $newPageBefore = false;
        foreach ($validatedElements as $value) {
            if ($value === 0) {
                if ($newPageBefore) return response("Invalid form (new page elements are next to each other).", 400);
                else $newPageBefore = true;
            } else if ($value === 1) {
                $newPageBefore = false;
            }
        }
        try {
            DB::transaction(function () use ($validatedQuestions, $userId, $formProps, $updateSlug, $updateHasPrivateToken) {
                $formSlug = null;
                if ($updateSlug) $formSlug = $updateSlug;
                else $formSlug = Uuid::uuid4()->toString();

                if ($updateHasPrivateToken === false) $formProps['has_private_token'] = false;
                if ($updateHasPrivateToken === true) $formProps['has_private_token'] = true;

                $newForm = Form::create([
                    'user_id' => $userId,
                    'slug' => $formSlug,
                    'name' => $formProps['header'],
                    'description' => $formProps['description'],
                    'start_time' => $formProps['start_time'],
                    'end_time' => $formProps['end_time'],
                    'has_private_token' => $formProps['has_private_token'],
                ]);

                foreach ($validatedQuestions as $item) {
                    $newFormElement = FormElement::create([
                        'order' => $item['order'],
                        'form_id' => $newForm->id
                    ]);
                    if ($item['type'] !== "new_page") {
                        $newInputElement = InputElement::create([
                            'header' => $item['header'],
                            'is_mandatory' => $item['is_mandatory'],
                            'form_element_id' => $newFormElement->id
                        ]);
                        switch ($item['type']) {
                            case 'text':
                            {
                                TextInput::create([
                                    'min_length' => $item['min_length'],
                                    'max_length' => $item['max_length'],
                                    'strict_length' => $item['strict_length'],
                                    'input_element_id' => $newInputElement->id
                                ]);
                                break;
                            }
                            case 'number':
                            {
                                NumberInput::create([
                                    'min' => $item['min'],
                                    'max' => $item['max'],
                                    'can_be_decimal' => $item['can_be_decimal'],
                                    'input_element_id' => $newInputElement->id
                                ]);
                                break;
                            }
                            case 'date':
                            {
                                DateInput::create([
                                    'min' => $item['min'],
                                    'max' => $item['max'],
                                    'input_element_id' => $newInputElement->id
                                ]);
                                break;
                            }
                            case 'boolean':
                            {
                                BooleanInput::create([
                                    'input_element_id' => $newInputElement->id
                                ]);
                                break;
                            }
                            case 'select':
                            {
                                $newSelectInput = SelectInput::create([
                                    'is_multiselect' => $item['is_multiselect'],
                                    'min_amount_of_answers' => $item['min_amount_of_answers'],
                                    'max_amount_of_answers' => $item['max_amount_of_answers'],
                                    'strict_amount_of_answers' => $item['strict_amount_of_answers'],
                                    'has_hidden_label' => $item['has_hidden_label'],
                                    'input_element_id' => $newInputElement->id
                                ]);

                                foreach ($item['choices'] as $choice) {
                                    SelectInputChoice::create([
                                        'text' => $choice['text'],
                                        'hidden_label' => $choice['hidden_label'],
                                        'order' => $choice['order'],
                                        'select_input_id' => $newSelectInput->id
                                    ]);
                                }
                                break;
                            }
                        }
                    } else {
                        NewPage::create(['form_element_id' => $newFormElement->id]);
                    }
                }

                //update form purpose
                if ($updateHasPrivateToken === null) {
                    if ($formProps['has_private_token']) {
                        foreach ($formProps['private_emails'] as $validatedEmail) {
                            $token = Uuid::uuid4()->toString();
                            FormPrivateAccessToken::create([
                                'token' => $token,
                                'email' => $validatedEmail,
                                'form_id' => $newForm->id,
                            ]);

                            Mail::to($validatedEmail)->send(new FormInvitation($token, $newForm));
                        }
                    }
                }
            });
        } catch (Exception $exception) {
            dd($exception);
            //return response("{$exception->getMessage()}", 500);
        }

        return response("Form has been created.", 200);
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show($slug)
    {
        $form = Form::where('slug', $slug)->first();
        if ($form) {
            if ($form->has_private_token) return response('You can access this form only with valid token.', 400);
            $currentTime = time();
            $formEndTime = strtotime($form->end_time);
            $formStartTime = strtotime($form->start_time);

            if ($currentTime >= $formEndTime) {
                return response('Expired - no longer available', 410);
            }
            if ($currentTime <= $formStartTime) {
                return response('Not available at this moment', 423);
            }

            return $form;
        } else return response('Not Found', 404);
    }

    public function privateShow($token)
    {
        $privateAccessToken = FormPrivateAccessToken::where('token', $token)->first();
        //todo: try to rewrite using show method above
        if ($privateAccessToken) {
            if ($privateAccessToken->was_used) return response('Token is expired', 410);
            else {
                $form = (Form::where('id', $privateAccessToken->form_id)->first())->makeHidden(['slug']);
                if ($form) {
                    $currentTime = time();
                    $formEndTime = strtotime($form->end_time);
                    $formStartTime = strtotime($form->start_time);

                    if ($currentTime >= $formEndTime) {
                        return response('Expired - no longer available', 410);
                    }
                    if ($currentTime <= $formStartTime) {
                        return response('Not available at this moment', 423);
                    }

                    return $form;
                } else return response('Bad request', 400);
            }
        } else return response('Not found', 404);
    }

    public function duplicateWithAuth(Request $request)
    {
        $userId = Auth::user()->id;
        if (!$userId) return response("Unauthorized.", 401);

        $form = null;
        if (array_key_exists('slug', $request->all())) {
            if ($request->all()['slug']) {
                $slug = strval($request->all()['slug']);
                $form = Form::where(['slug' => $slug, 'user_id' => $userId])->first();
                if (!$form) return response("Not found.", 404);
            } else return response("Missing slug", 400);
        } else return response("Missing slug", 400);

        $formProps = [
            'name' => "",
            'description' => "",
            "start_time" => "",
            "end_time" => "",
            'has_private_token' => false,
            'private_emails' => []
        ];

        if (array_key_exists('name', $request->all())) {
            if ($request->all()['name']) $formProps['name'] = strval($request->all()['name']);
        } else return response("Missing form name", 400);

        if (array_key_exists('description', $request->all())) {
            if ($request->all()['description']) $formProps['description'] = strval($request->all()['description']);
        }

        if (array_key_exists('start_time', $request->all()) && array_key_exists('end_time', $request->all())) {
            $startDate = date('Y-m-d H:i:s', strtotime(str_replace("T", " ", $request->all()['start_time'])));
            $endDate = date('Y-m-d H:i:s', strtotime(str_replace("T", " ", $request->all()['end_time'])));

            if ($this->validateDate($startDate) && $this->validateDate($endDate)) {
                if (new DateTime($request->all()['start_time']) < new DateTime($request->all()['end_time'])) {
                    $currentTime = time();
                    $formEndTime = strtotime($endDate);
                    if ($currentTime > $formEndTime) {
                        return response("Invalid data (creating expired form is not allowed).", 400);
                    }

                    $formProps['start_time'] = new DateTime($request->all()['start_time']);
                    $formProps['end_time'] = new DateTime($request->all()['end_time']);
                } else return response("Invalid data (start is higher than end).", 400);
            } else {
                return response("Invalid data (invalid start/end date) 2.", 400);
            }
        } else return response("Invalid data (missing start/end date) 1.", 400);


        if (array_key_exists('has_private_token', $request->all())) {
            if (is_bool($request->all()['has_private_token']) && $request->all()['has_private_token']) $formProps['has_private_token'] = true;
        }

        if ($formProps['has_private_token']) {
            if (array_key_exists('private_emails', $request->all())) {
                if ($request->all()['private_emails']) {
                    $dataToValidate['emails'] = explode("\n", $request->all()['private_emails']);

                    $validator = Validator::make($dataToValidate, [
                        'emails.*' => 'email|required|regex:/(.+)@(.+)\.(.+)/i',
                    ]);

                    if ($validator->fails()) {
                        return response("Invalid data (invalid private emails - raw text input mode).", 400);
                    } else {
                        $formProps['private_emails'] = $dataToValidate['emails'];
                    }
                } else {
                    return response("Invalid data (missing array or formatted string of private emails).", 400);
                }
            } else return response("Invalid data (missing array of private emails).", 400);
        }


        $formSlug = Uuid::uuid4()->toString();
        try {
            DB::transaction(function () use ($form, $userId, $formProps, $formSlug) {
                $newForm = Form::create([
                    'user_id' => $userId,
                    'slug' => $formSlug, //new one!

                    //inserted by user
                    'name' => $formProps['name'],
                    'description' => $formProps['description'],
                    'start_time' => $formProps['start_time'],
                    'end_time' => $formProps['end_time'],
                    'has_private_token' => $formProps['has_private_token'],
                    //****************
                ]);

                foreach ($form->formElements as $item) {
                    $newFormElement = FormElement::create([
                        'order' => $item['order'],
                        'form_id' => $newForm->id
                    ]);

                    if ($item->inputElement) {
                        $newInputElement = InputElement::create([
                            'header' => $item->inputElement->header,
                            'is_mandatory' => $item->inputElement->is_mandatory,
                            'form_element_id' => $newFormElement->id
                        ]);

                        if ($item->inputElement->dateInput) {
                            DateInput::create([
                                'min' => $item->inputElement->dateInput->min,
                                'max' => $item->inputElement->dateInput->max,
                                'input_element_id' => $newInputElement->id
                            ]);
                        } else if ($item->inputElement->textInput) {
                            TextInput::create([
                                'min_length' => $item->inputElement->textInput->min_length,
                                'max_length' => $item->inputElement->textInput->max_length,
                                'strict_length' => $item->inputElement->textInput->strict_length,
                                'input_element_id' => $newInputElement->id
                            ]);
                        } else if ($item->inputElement->numberInput) {
                            NumberInput::create([
                                'min' => $item->inputElement->numberInput->min,
                                'max' => $item->inputElement->numberInput->max,
                                'can_be_decimal' => $item->inputElement->numberInput->can_be_decimal,
                                'input_element_id' => $newInputElement->id
                            ]);
                        } else if ($item->inputElement->booleanInput) {
                            BooleanInput::create([
                                'input_element_id' => $newInputElement->id
                            ]);
                        } else if ($item->inputElement->selectInput) {
                            $newSelectInput = SelectInput::create([
                                'is_multiselect' => $item->inputElement->selectInput->is_multiselect,
                                'min_amount_of_answers' => $item->inputElement->selectInput->min_amount_of_answers,
                                'max_amount_of_answers' => $item->inputElement->selectInput->max_amount_of_answers,
                                'strict_amount_of_answers' => $item->inputElement->selectInput->strict_amount_of_answers,
                                'has_hidden_label' => $item->inputElement->selectInput->has_hidden_label,
                                'input_element_id' => $newInputElement->id
                            ]);
                            foreach ($item->inputElement->selectInput->selectInputChoices as $choice) {
                                SelectInputChoice::create([
                                    'text' => $choice->text,
                                    'hidden_label' => $choice->hidden_label,
                                    'order' => $choice->order,
                                    'select_input_id' => $newSelectInput->id
                                ]);
                            }
                        }
                    } else {
                        NewPage::create([
                            'form_element_id' => $newFormElement->id
                        ]);
                    }
                }

                if ($formProps['has_private_token']) {
                    foreach ($formProps['private_emails'] as $validatedEmail) {
                        $token = Uuid::uuid4()->toString();
                        FormPrivateAccessToken::create([
                            'token' => $token,
                            'email' => $validatedEmail,
                            'form_id' => $newForm->id,
                        ]);
                        Mail::to($validatedEmail)->send(new FormInvitation($token, $newForm));
                    }
                }
            });
        } catch (Exception $exception) {
            dd($exception);
            //return response("{$exception->getMessage()}", 500);
        }

        return response("Form has been duplicated successfully.", 200)->header('DuplicatedFormSlug', $formSlug);
    }

    public function showWithAuth($slug)
    {
        if (Auth::user()) {
            $userId = Auth::user()->id;
            $form = Form::where('slug', $slug)->with('formPrivateAccessTokens')->first();

            if ($form) {
                if ($form->user_id != $userId) {
                    return response("Unauthorized - you don't own this form!", 401);
                }

                foreach ($form->formPrivateAccessTokens as $key => $value) {
                    unset($form->formPrivateAccessTokens[$key]->token);
                }

                $currentTime = time();
                $formEndTime = strtotime($form->end_time);
                $formStartTime = strtotime($form->start_time);

                if ($currentTime >= $formEndTime) $form->is_expired = true;
                else $form->is_expired = false;

                if ($formStartTime <= $currentTime) $form->was_already_published = true;
                else $form->was_already_published = false;

                if (!$form->is_expired && $form->was_already_published) $form->is_opened = true;
                else $form->is_opened = false;

                return $form;
            } else return response('Not Found', 404);
        } else {
            return response("Unauthorized - log in to show your forms...", 401);
        }
    }

    public function publishResults(Request $request, $slug)
    {
        if (!($request->all() && is_array($request->all()))) {
            return response("Bad request", 400);
        }
        $user = Auth::user();
        if ($user) {
            $form = Form::where(['slug' => $slug, 'user_id' => $user->id])->first();
            if ($form) {
                $correspondingIds = [];
                $validatedQuestions = [];
                $validatedHasPublicResults = null;

                //get all corresponding ids for form
                foreach ($form->formElements as $value) {
                    if ($value->inputElement) if ($value->inputElement->id) {
                        $correspondingIds[] = $value->inputElement->id;
                    }
                }

                //validate has_public_results
                if (array_key_exists("has_public_results", $request->all())) {
                    if ($request->all()["has_public_results"]) $validatedHasPublicResults = true;
                    else $validatedHasPublicResults = false;
                    unset($request->all()["has_public_results"]);
                }

                //if validate has_public_results -> set which ids are true or false
                if ($validatedHasPublicResults) {
                    $validatedQuestions = ["true" => [], "false" => []];
                    foreach ($request->all() as $key => $value) {
                        if (in_array($key, $correspondingIds)) {
                            if ($value) $validatedQuestions["true"][] = $key;
                            else $validatedQuestions["false"][] = $key;
                        }
                    }
                    //check if at least one question is selected to be public (at this moment form results must be public)
                    if (count($validatedQuestions["true"]) <= 0)
                        return response("Bad request (at least one public question expected)", 400);
                }

                try {
                    DB::transaction(function () use ($form, $validatedHasPublicResults, $validatedQuestions, $correspondingIds) {
                        $form->has_public_results = $validatedHasPublicResults;
                        $form->save();

                        //if form results are private -> all questions become private
                        if (!$validatedHasPublicResults) {
                            InputElement::whereIn('id', $correspondingIds)
                                ->update(['has_public_results' => false]);
                            return response("Form publication restrictions has been modified successfully (now private)", 200);
                        } else {
                            if (count($validatedQuestions['true']) > 0)
                                InputElement::whereIn('id', $validatedQuestions['true'])
                                    ->update(['has_public_results' => true]);
                            if (count($validatedQuestions['false']) > 0)
                                InputElement::whereIn('id', $validatedQuestions['false'])
                                    ->update(['has_public_results' => false]);
                        }
                    });
                } catch (Exception $exception) {
                    dd($exception);
                    //return response("{$exception->getMessage()}", 500);
                }
                return response("Form publication restrictions has been modified successfully", 200);
            } else return response("Not found.", 404);
        } else return response("Unauthorized.", 401);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $slug)
    {
        $user = Auth::user();
        if (!$user) return response("Unauthorized - log in to update your forms...", 401);
        $form = Form::where(["slug" => $slug, "user_id" => $user->id])->first();
        if ($form) {
            $currentTime = time();
            $formStartTime = strtotime($form->start_time);
            if ($formStartTime <= $currentTime) return response("Requested form has been already published - it cannot be updated anymore.", 400);

            DB::beginTransaction();
            try {
                $temporarySlug = Uuid::uuid4()->toString();
                $storeResponse = $this->store($request, $temporarySlug, $form->has_private_token);
                if ($storeResponse->status() === 200) {
                    if ($form->has_private_token) {
                        $privateTokens = FormPrivateAccessToken::where(["form_id" => $form->id])->get();
                        $this->destroy($slug);
                        $updatedForm = Form::where("slug", $temporarySlug)->first();
                        $updatedForm->update(["slug" => $slug]);
                        foreach ($privateTokens as $token) {
                            FormPrivateAccessToken::create([
                                'was_used' => $token->was_used,
                                'token' => $token->token,
                                'email' => $token->email,
                                'form_id' => $updatedForm->id,
                            ]);
                        }
                    } else {
                        $this->destroy($slug);
                        $updatedForm = Form::where("slug", $temporarySlug)->first();
                        $updatedForm->update(["slug" => $slug]);
                    }
                } else {
                    return $storeResponse;
                    throw new Exception("Bad request");
                }

                DB::commit();
            } catch (Exception $e) {
                DB::rollback();
                if ($e->getMessage() === "Bad request") return $storeResponse;
                else return response("Form update has failed due to unhandled error.", 500);
            }


            return response("Form was updated successfully", 200);
        } else return response("Requested form (update) was not found", 404);
    }


    public function updateAccess(Request $request, $slug)
    {
        //validate user, existing form
        if (!$request->all()) return response("Bad request", 400);
        $user = Auth::user();
        if ($user) {
            $form = Form::where(['slug' => $slug, 'user_id' => $user->id])->first();
            if ($form) {
                $validatedData = ['has_private_token' => null, 'emailsToInvalidate' => [], 'newInvitedEmails' => []];
                if (array_key_exists("has_private_token", $request->all())) {
                    try {
                        if ($request->all()['has_private_token']) $validatedData['has_private_token'] = true;
                        else $validatedData['has_private_token'] = false;

                        //invalide and invite
                        if ($validatedData['has_private_token']) {
                            $existingTokens = FormPrivateAccessToken::where('form_id', $form->id)->get();

                            if (array_key_exists("newInvitedEmails", $request->all()) && $request->all()['newInvitedEmails']) {
                                $inviteEmails = explode("\n", $request->all()['newInvitedEmails']);
                                $existingTokenEmails = $existingTokens->map(function ($x) {
                                    return $x->email;
                                })->toArray();

                                foreach ($inviteEmails as $email) {
                                    if (preg_match('/(.+)@(.+)\.(.+)/', $email)) {
                                        if (!in_array($email, $existingTokenEmails)) {
                                            $validatedData['newInvitedEmails'][] = $email;
                                        } else return response('Invalid data (duplicit emails).', 400);
                                    } else return response('Invalid data (invalid emails).', 400);
                                }

                            }

                            //invite emails (was public)
                            if (!$form->has_private_token) {
                                DB::transaction(function () use ($validatedData, $form) {
                                    $form->update(['has_private_token' => true]);
                                    if ($validatedData['newInvitedEmails']) {
                                        foreach ($validatedData['newInvitedEmails'] as $validatedEmail) {
                                            //TODO: try to catch duplicit emails
                                            $token = Uuid::uuid4()->toString();
                                            FormPrivateAccessToken::create([
                                                'token' => $token,
                                                'email' => $validatedEmail,
                                                'form_id' => $form->id,
                                            ]);

                                            Mail::to($validatedEmail)->send(new FormInvitation($token, $form, true));
                                        }
                                    }
                                });
                            } //invalidate and invite emails (was private)
                            else {
                                if (array_key_exists('emailsToInvalidate', $request->all()) && is_array($request->all()['emailsToInvalidate'])) {
                                    $currentTokenIds = $existingTokens->map(function ($x) {
                                        return $x->id;
                                    })->toArray();
                                    foreach ($request->all()['emailsToInvalidate'] as $key => $value) {
                                        if (is_numeric($key)) {
                                            if (in_array(intval($key), $currentTokenIds)) {
                                                if ($value) {
                                                    $validatedData['emailsToInvalidate'][] = intval($key);
                                                }
                                            }
                                        }
                                    }
                                }
                                DB::transaction(function () use ($validatedData, $form) {
                                    if ($validatedData['emailsToInvalidate']) {
                                        FormPrivateAccessToken::whereIn('id', $validatedData['emailsToInvalidate'])->delete();
                                    }
                                    if ($validatedData['newInvitedEmails']) {
                                        foreach ($validatedData['newInvitedEmails'] as $validatedEmail) {
                                            $token = Uuid::uuid4()->toString();
                                            FormPrivateAccessToken::create([
                                                'token' => $token,
                                                'email' => $validatedEmail,
                                                'form_id' => $form->id,
                                            ]);

                                            Mail::to($validatedEmail)->send(new FormInvitation($token, $form, true));
                                        }
                                    }
                                });
                            }
                        } //has_private_token was changed to false
                        else if ($form->has_private_token && !$validatedData['has_private_token']) {
                            //remove tokens
                            DB::transaction(function () use ($form) {
                                FormPrivateAccessToken::where('form_id', $form->id)->delete();
                                $form->update(['has_private_token' => false]);
                            });
                        }
                    } catch (Exception $e) {
                        return response($e->getMessage(), $e->getCode());
                    }

                } else return response("Invalid data (missing has_private_token value)", 400);
            } else {
                return response("Not found", 404);
            }
        } else return response("Unauthorized", 401);
        //transaction
        //set props
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($slug)
    {
        $user = Auth::user();
        if (!$user) return response("Unauthorized - log in to delete forms...", 401);
        else {
            DB::transaction(function () use ($slug, $user) {
                $form = Form::where(["slug" => $slug, "user_id" => $user->id])->first();
                if ($form) {
                    if ($form->user_id == Auth::user()->id) {
                        if ($form->delete()) {
                            return response("Form was deleted.", 200);
                        } else return response("Form deletion failed.", 500);
                    }
                } else return response("Requested form (delete) was not found", 404);
            });
        }
    }
}
