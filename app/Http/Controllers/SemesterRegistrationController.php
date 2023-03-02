<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\FavoriteTime;
use App\Models\Student;
use App\Models\Subscribe;
use App\Service\Payment\Checkout;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use function GuzzleHttp\json_decode;

class SemesterRegistrationController extends Controller
{

    private $payment_link;
    private $secret;
    private $public;

    public function __construct()
    {
        $config = config('checkoutpayment');
        $this->payment_link = $config['checkout_link'];
        $this->secret = $config['checkout_sk'];
        $this->public = $config['checkout_pk'];
    }

    public function indexOneToOne()
    {
        $countries = Country::query()->where('lang', '=', App::getLocale())->get();
        $favorite_times_male = FavoriteTime::query()->where('section',  '=', 'male')->get();
        $favorite_times_female = FavoriteTime::query()->where('section',  '=', 'female')->get();
        $course = Course::query()->where('code',  '=', 'fourth_to_fourth')->first();

        return view('one-to-one', ['countries' => $countries, 'favorite_times_male' => $favorite_times_male , 'favorite_times_female' => $favorite_times_female, 'course' => $course]);
    }

    public function thankYouPage()
    {
        if(request()->query('cko-session-id')){
            $client = new Client(['base_uri' => $this->payment_link]);

            try {
                $response = $client->request('GET', '/payments/' . request()->query('cko-session-id'),
                    [
                        'headers' => [
                            'Authorization' => config('checkoutpayment.checkout_sk')
                        ]
                    ]);

                $data = json_decode($response->getBody()->getContents());

                if ($response->getStatusCode() != 404){

                    $subscribe = Subscribe::query()
                        ->where('payment_id', '=', $data->id)
                        ->first();

                    $result = $subscribe->update([
                        'payment_status' => $data->status,
                        'response_code'  => $data->actions[0]->response_code ?? '-',
                    ]);

                    if ($data->approved){

                        $coupon = Coupon::find($subscribe->coupon_id);
                        if ($coupon && @$coupon->is_valid){
                            $coupon->use($subscribe->student_id);
                        }

                        $subscribe->student->customPrice->update([
                            'status' => '0',
                        ]);

                        session()->flash('success', __('resubscribe.The registration process has been completed successfully'));
                    }else{
                        session()->flash('error', __('resubscribe.Payment failed'));
                    }

                }else{
                    session()->flash('error', __('resubscribe.Payment failed'));
                }
            }catch (\GuzzleHttp\Exception\ClientException $e) {
//                $response = $e->getResponse();
                session()->flash('error', __('resubscribe.Payment failed'));
            }
        }

        $countries = Country::query()->where('lang', '=', App::getLocale())->get();
        $favorite_times_male = FavoriteTime::query()->where('section',  '=', 'male')->get();
        $favorite_times_female = FavoriteTime::query()->where('section',  '=', 'female')->get();
        $course = Course::query()->where('code',  '=', 'fourth_to_fourth')->first();

        if (session()->get('subscribe_id')){

            $subscribe_id = session()->get('subscribe_id');

            $subscribe = Subscribe::query()
                ->where('id', '=', $subscribe_id)
                ->first();

            $coupon = Coupon::find($subscribe->coupon_id);
            if ($coupon && @$coupon->is_valid){

                if ($coupon && @$coupon->is_valid){
                    $coupon->use($subscribe->student_id);
                }

                $subscribe->student->customPrice->update([
                    'status' => '0',
                ]);

                $result = $subscribe->update([
                    'payment_status' => 'Captured',
                    'response_code' => '-',
                ]);

                session()->forget('subscribe_id');

                return view('thank-you', ['countries' => $countries, 'favorite_times_male' => $favorite_times_male , 'favorite_times_female' => $favorite_times_female, 'course' => $course]);
            }

        }

        if (! (session('error') || session('success')) ) {
            return redirect()->route('semester.indexOneToOne');
        }

        return view('thank-you', ['countries' => $countries, 'favorite_times_male' => $favorite_times_male , 'favorite_times_female' => $favorite_times_female, 'course' => $course]);
    }

    public function getStudentInfo()
    {
        $student = Student::query()
            ->where('serial_number', '=', \request()->std_number)
            ->where('section', '=', \request()->std_section)
            ->first();

        if (\request()->query('form_type') == 'one_to_one' && $student){
            if ($student->path != 'حفظ'){
                return response()->json(['msg' => __('Sorry just')], 500);
            }
        }

        if ($student){
            $amount = Course::query()->where('code', 'fourth_to_fourth')->first()->amount;
            $discount_reason = false;

            if ($student->customPrice){

                if (!empty($student->customPrice->discount_value)){
                    $amount = $amount - ($student->customPrice->discount_value*100);
                    $discount_reason = $student->customPrice->discount_reason;
                }elseif(!empty($student->customPrice->discount_percent)){
                    $amount = $amount - ( $amount * ($student->customPrice->discount_percent/100) );
                    $discount_reason = $student->customPrice->discount_reason;
                }

            }

            return response()->json(['name' => $student->name, 'amount' => $amount/100, 'discount_reason' => $discount_reason], 200, [], JSON_UNESCAPED_UNICODE);
        }

        return response()->json(['msg' => __('resubscribe.serial number is incorrect')], 404);
    }

}
