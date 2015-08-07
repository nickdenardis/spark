<?php

namespace Laravel\Spark\Http\Controllers\Settings;

use Exception;
use Laravel\Spark\Spark;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Laravel\Spark\Events\User\ProfileUpdated;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;

class ProfileController extends Controller
{
    use ValidatesRequests;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Update the user's profile information.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updateUserProfile(Request $request)
    {
        if (! is_null($response = $this->validateUserProfile($request))) {
            return $response;
        }

        $originalEmail = Auth::user()->email;

        if (Spark::$updateProfilesWith) {
            call_user_func(Spark::$updateProfilesWith, $request);
        } else {
            Auth::user()->fill($request->all())->save();
        }

        if (Auth::user()->stripe_id && $originalEmail !== Auth::user()->email) {
            $this->updateStripeEmailAddress();
        }

        event(new ProfileUpdated(Auth::user()));

        return redirect('settings?tab=profile')
                    ->with('updateProfileSuccessful', true);
    }

    /**
     * Validate the incoming request to update the user's profile.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    protected function validateUserProfile(Request $request)
    {
        if (Spark::$validateProfileUpdatesWith) {
            return $this->validateUserProfileWithCustomValidator($request);
        } else {
            $validator = Validator::make($request->all(), [
                'name' => 'required|max:255',
                'email' => 'required|email|unique:users,email,'.Auth::id()
            ]);

            if ($validator->fails()) {
                return redirect('settings?tab=profile')
                            ->withErrors($validator, 'updateProfile');
            }
        }
    }

    /**
     * Validate the incoming request to update the user's profile with a custom validator.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    protected function validateUserProfileWithCustomValidator(Request $request)
    {
        $validator = call_user_func(Spark::$validateProfileUpdatesWith, $request);

        $validator = $validator instanceof ValidatorContract
                        ? $validator
                        : Validator::make($request->all(), $validator);

        if ($validator->fails()) {
            return redirect('settings?tab=profile')->withErrors($validator, 'userProfile');
        }
    }

    /**
     * Update the user's e-mail address on Stripe.
     *
     * @return void
     */
    protected function updateStripeEmailAddress()
    {
        $customer = Auth::user()->subscription()->getStripeCustomer();

        $customer->email = Auth::user()->email;

        $customer->save();
    }
}