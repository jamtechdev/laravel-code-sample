<?php

namespace App\Http\Controllers\Api\v1\Authentication;

use DB;
use Str;
use Auth;
use Hash;
use Exception;
use Validator;
use Carbon\Carbon;
use App\Models\User;
use App\Models\Profile;
use App\Models\Provider;
use App\Models\EmailTemplate;
use Illuminate\Http\Request;
use App\Constants\ResponseMessage;
use App\Constants\ResponseCode;
use App\Constants\SocialProviders;
use Laravel\Socialite\Facades\Socialite;
use App\Http\Resources\AuthResource as AuthResource;
use App\Http\Controllers\Controller as MasterController;
use App\Http\Controllers\Api\v1\Payment\StripeController;
use App\Http\Controllers\Api\v1\Payment\StripeConnectAccountController;
use App\Mail\{ForgetPasswordMail, PasswordResetMail, SignUpWelcomeMail};

class Controller extends MasterController
{
    /**
     *
     * @OA\Post(
     *      path="/api/v1/auth/signIn",
     *      operationId="signIn",
     *      tags={"Authentication API's"},
     *      summary="Member Login",
     *      description="Return Authenticated member details",
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(ref="#/components/schemas/AuthenticationRequest")
     *      ),
     *      @OA\Response(
     *          response=202,
     *          description="Successful"
     *       )
     * )
     */
    function signIn(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required',
            ]);

            if ($validator->fails()) {
                return validationErrorHandler($validator->errors());
            }

            $remember_me = $request->has('remember_me') ? true : false;

            if (Auth::guard('web')->attempt(['email' => $request->email, 'password' => $request->password], $remember_me)) {

                $user = User::with(['profile.freelancer_profile','profile.client_profile'])->where('email', $request->email)->first();
                // $user->tokens()->delete();
                $token =  $user->createToken($user->name)->plainTextToken;
                $user['token'] = $token;
                /**
                 * Mail Send Start
                 */
                $email_data = array(
                    'name' => $user['name'],
                    'email' => $user['email'],
                );

                return successHandler(
                    new \App\Http\Resources\AuthResource($user),
                    ResponseCode::OK_CODE,
                    ResponseMessage::LOGGED_IN_SUCCESS_MESSAGE
                );
            }
            return unAuthorizedErrorHandler();
        } catch (Exception $e) {
            return serverErrorHandler($e);
        }
    }
    /**
     * @OA\Post(
     *      path="/api/v1/auth/signUpAsFreelancer",
     *      operationId="signUp",
     *      tags={"Authentication API's"},
     *      summary="Member Registration",
     *      description="Return Authenticated member details",
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(ref="#/components/schemas/SignupRequest")
     *      ),
     *      @OA\Response(
     *          response=202,
     *          description="Successful"
     *       )
     * )
     */
    public function signUpAsFreelancer(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'unique:users|required|email',
                'first_name' => 'required',
                'last_name' => 'required',
                'password' => 'required|confirmed|min:6',
                'phone' => 'required',
                'country_id' => 'numeric|required',
                'hear_about_us' => 'nullable',
                'english_proficiency' => 'nullable',
                'primary_skills' => 'nullable',
                'main_reason_for_applying' => 'required',
            ]);

            if ($validator->fails()) {
                return validationErrorHandler($validator->errors());
            }

            $user = User::create([
                'uuid' => getUID(),
                'name' => $request->first_name,
                'email' => $request->email,
                'password' => bcrypt($request->password)
            ]);
            $user->assignRole("freelancer");
            /**
             * Create Profile Object
             */
            $profile = new Profile;
            $profile->uid = getUID();
            $profile->first_name = $request->first_name;
            $profile->last_name = $request->last_name;
            $profile->phone = $request->phone;
            $profile->country_id = $request->country_id;
            $profile->hear_about_us = $request->hear_about_us;
            $user->profile()->save($profile);
            /**
             * Create Freelance Profile Object
             */
            $freelance_profile = new \App\Models\FreelancerProfile;
            $freelance_profile->is_verified = false;
            $freelance_profile->profile_id = $user->profile->id;
            $freelance_profile->main_reason_for_applying = $request->main_reason_for_applying;
            $freelance_profile->save();

            $token = $user->createToken($user->uuid)->plainTextToken;
            $user['token'] = $token;

            //-----------mail start--------
            $signUpMail = EmailTemplate::whereLabel('signup_welcome_mail')->first();
            $locale = app()->getLocale();
            $mailData = [
                'name' => $signUpMail->template[$locale]['name'] .' '. ucwords($request->first_name .' '. $request->last_name),
                'title' => $signUpMail->template[$locale]['title'],
                'body' => $signUpMail->template[$locale]['body'],
                'gratitude' => $signUpMail->template[$locale]['gratitude'],
                'suggestion' => $signUpMail->template[$locale]['suggestion'],
                'message' => $signUpMail->template[$locale]['message'],
            ];
            \Mail::to($request->email)->send(new SignUpWelcomeMail($mailData));
            //------------mail end----------

            return successHandler(
                new AuthResource($user),
                ResponseCode::CREATED_CODE,
                ResponseMessage::SIGNUP_SUCCESS_MESSAGE
            );
        } catch (Exception $e) {
            return serverErrorHandler($e);
        }
    }
    public function signUpAsClient(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'unique:users|required|email',
                'password' => 'required|confirmed|min:6',
                'first_name' => 'required',
                'last_name' => 'required',
                'phone' => 'required',
                'country_id' => 'numeric|required',
                'hear_about_us' => 'nullable',
                'company_name' => 'required',
                'employee_strength' => 'required',
                'service_needed' => 'required',
                'want_hire' => 'nullable',
                'hire_remote' => 'required',
                'project_start_on' => 'nullable',
                'how_to_meet' => 'required',

            ]);
            if ($validator->fails()) {
                return validationErrorHandler($validator->errors());
            }
            $user = User::create([
                'uuid' => getUID(),
                'name' => $request->first_name,
                'email' => $request->email,
                'password' => bcrypt($request->password)
            ]);
            $user->assignRole("client");
            /**
             * Create Profile Object
             */
            $profile = new Profile;
            $profile->uid = getUID();
            $profile->first_name = $request->first_name;
            $profile->last_name = $request->last_name;
            $profile->phone = $request->phone;
            $profile->country_id = $request->country_id;
            $profile->hear_about_us = $request->hear_about_us;

            $user->profile()->save($profile);
            /**
             * Create Client Profile Object
             */
            $client_profile = new \App\Models\ClientProfile;
            $client_profile->is_verified = false;
            $client_profile->profile_id = $user->profile->id;
            $client_profile->company_name = $request->company_name;
            $client_profile->company_website = $request->company_website;
            $client_profile->employee_strength = $request->employee_strength;
            $client_profile->service_needed = $request->service_needed;
            $client_profile->want_hire = $request->want_hire;
            $client_profile->sap_modules = $request->sap_modules;
            $client_profile->hire_remote = $request->hire_remote;
            $client_profile->project_start_on = $request->project_start_on;
            $client_profile->how_to_meet = $request->how_to_meet;
            $client_profile->save();

            $token = $user->createToken($user->uuid)->plainTextToken;
            $user['token'] = $token;

            //-----------mail start--------
            $signUpMail = EmailTemplate::whereLabel('signup_welcome_mail')->first();
            $locale = app()->getLocale();
            $mailData = [
                'name' => $signUpMail->template[$locale]['name'] .' '. ucwords($request->first_name .' '. $request->last_name),
                'title' => $signUpMail->template[$locale]['title'],
                'body' => $signUpMail->template[$locale]['body'],
                'gratitude' => $signUpMail->template[$locale]['gratitude'],
                'suggestion' => $signUpMail->template[$locale]['suggestion'],
                'message' => $signUpMail->template[$locale]['message'],
            ];
            \Mail::to($request->email)->send(new SignUpWelcomeMail($mailData));
            //------------mail end----------

            return successHandler(
                new AuthResource($user),
                ResponseCode::CREATED_CODE,
                ResponseMessage::SIGNUP_SUCCESS_MESSAGE
            );
        } catch (Exception $e) {
            return serverErrorHandler($e);
        }
    }
    public function logout(Request $request)
    {
        if (\App\Models\GoogleFcmToken::where('fcm_token', $request->token)->first()) {
            \App\Models\GoogleFcmToken::where('fcm_token', $request->token)->delete();
        }
        return response()->json(['message' => 'success', 'message' => "Logout successfully."]);
    }
    /**
     * @OA\Post(
     *      path="/api/v1/auth/forgotPassword",
     *      operationId="forgotPassword",
     *      tags={"Authentication API's"},
     *      summary="Forgot Member Password",
     *      description="Return Message",
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(ref="#/components/schemas/ForgotPasswordRequest")
     *      ),
     *      @OA\Response(
     *          response=202,
     *          description="Successful"
     *       )
     * )
     */
    public function forgotPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
            ]);
            if ($validator->fails()) {
                return validationErrorHandler($validator->errors());
            }

            $user = User::whereEmail($request->email)->first();
            if (!is_object($user)) {
                return successHandler(
                    null,
                    ResponseCode::NO_CONTENT_CODE,
                    ResponseMessage::FORGOT_PASSWORD_USER_NOT_FOUND_MESSAGE
                );
            }

            //create user's token
            DB::table('password_resets')->insert([
                'email' => $request->email,
                'token' => Str::random(60),
                'created_at' => Carbon::now()
            ]);

            $tokenData = DB::table('password_resets')->whereEmail($request->email)->first();
            if ($this->sendResetEmail($request->email, $tokenData->token)) {
                return successHandler(
                    null,
                    ResponseCode::OK_CODE,
                    ResponseMessage::RESET_PASSWORD_LINK_SEND_MESSAGE
                );
            }
        } catch (Exception $e) {
            return serverErrorHandler($e);
        }
    }

    private function sendResetEmail($email, $token)
    {
        try {
            //-----------mail start--------
            $fogPwdMail = EmailTemplate::whereLabel('forget_password_mail')->first();
            $locale = app()->getLocale();
            $user = User::with('profile')->whereEmail($email)->first();
            $mailData = [
                'name' => $fogPwdMail->template[$locale]['name'] .' '. ucwords($user->profile->first_name .' '. $user->profile->last_name),
                'button' => $fogPwdMail->template[$locale]['button'],
                'link' => url('https://xx?reset-token='.$token.'&for-mail='.$user->email),
                'token' => $fogPwdMail->template[$locale]['token'] .' = '. $token
            ];
            \Mail::to($user->email)->send(new ForgetPasswordMail($mailData));
            //------------mail end----------
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function sendSuccessEmail($email)
    {
        try {
            //-----------mail start--------
            $resPwdMail = EmailTemplate::whereLabel('reset_password_mail')->first();
            $locale = app()->getLocale();
            $user = User::with('profile')->whereEmail($email)->first();
            $mailData = [
                'name' => $resPwdMail->template[$locale]['name'] .' '. ucwords($user->profile->first_name .' '. $user->profile->last_name),
                'email' => $user->email,
                'message' => $resPwdMail->template[$locale]['message']
            ];
            \Mail::to($user->email)->send(new PasswordResetMail($mailData));
            //------------mail end----------
            return true;
        } catch (Exception $e) {
            return false;
        }
    }


    /**
     * @OA\Post(
     *      path="/api/v1/auth/resetPassword",
     *      operationId="resetPassword",
     *      tags={"Authentication API's"},
     *      summary="Reset Member Password",
     *      description="Return Message",
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(ref="#/components/schemas/ResetPasswordRequest")
     *      ),
     *      @OA\Response(
     *          response=202,
     *          description="Successful"
     *       )
     * )
     */
    public function resetPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users',
                'password' => 'required|confirmed',
                'token' => 'required'
            ]);

            if ($validator->fails()) {
                return validationErrorHandler($validator->errors());
            }

            $tokenData = DB::table('password_resets')->whereToken($request->token)->first();
            if (!$tokenData) {
                return errorHandler(
                    ResponseCode::BAD_REQUEST_CODE,
                    ResponseMessage::FORGOT_PASSWORD_TOKEN_INVALID_MESSAGE
                );
            }
            //if emailId exists then update password
            if (!$user = User::whereEmail($tokenData->email)->first()) {
                return notFoundErrorHandler(ResponseMessage::NOT_FOUND_USER_MESSAGE);
            }
            $user->password = Hash::make($request->password);
            $user->update();
            //delete the token
            DB::table('password_resets')->whereEmail($user->email)->delete();
            if ($this->sendSuccessEmail($request->email)) {
                return successHandler(
                    null,
                    ResponseCode::OK_CODE,
                    ResponseMessage::PASSWORD_RESET_SUCCESS_MESSAGE
                );
            }
        } catch (Exception $e) {
            return serverErrorHandler($e);
        }
    }


    /**
     * @OA\Post(
     *      path="/api/v1/auth/changePassword",
     *      operationId="change Member Account Password",
     *      tags={"Authentication API's"},
     *      summary="Change Member Account Password",
     *      description="Success Message",
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(ref="#/components/schemas/ChangeMemberAccountPasswordRequest")
     *      ),
     *    security={
     *          {"bearerAuth": {}}
     *       },
     *      @OA\Response(
     *          response=202,
     *          description="Successful"
     *       )
     * )
     */
    public function changePassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'old_password' => 'required|current_password:api',
                'password' => 'required|confirmed|max:12|min:8'
            ]);
            if ($validator->fails()) {
                return validationErrorHandler($validator->errors());
            }

            $request->user()->update([
                'password' => bcrypt($request->password)
            ]);

            return successHandler(
                null,
                ResponseCode::OK_CODE,
                ResponseMessage::PASSWORD_RESET_SUCCESS_MESSAGE
            );
        } catch (\Exception $e) {
            return serverErrorHandler($e);
        }
    }
}
