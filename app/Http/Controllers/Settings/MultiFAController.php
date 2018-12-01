<?php

namespace App\Http\Controllers\Settings;

use Illuminate\Http\Request;
use App\Models\User\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\RedirectsUsers;
use PragmaRX\Recovery\Recovery as PragmaRXRecovery;
use PragmaRX\Google2FALaravel\Support\Authenticator;

class MultiFAController extends Controller
{
    use RedirectsUsers;

    protected $redirectTo = '/settings/security';

    /**
     * Session var name to store secret code.
     */
    private $SESSION_TFA_SECRET = '2FA_secret';

    /**
     * Create a new authentication controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('web');
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function enableTwoFactor(Request $request)
    {
        //generate new secret
        $secret = $this->generateSecret();

        $user = $request->user();

        //generate image for QR barcode
        $imageDataUri = app('pragmarx.google2fa')->getQRCodeInline(
            $request->getHttpHost(),
            $user->email,
            $secret,
            200
        );

        $request->session()->put($this->SESSION_TFA_SECRET, $secret);

        return response()->json(['image' => $imageDataUri, 'secret' => $secret]);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function validateTwoFactor(Request $request)
    {
        //get user
        $user = $request->user();

        if (! is_null($user->google2fa_secret)) {
            return response()->json(['error' => trans('settings.2fa_enable_error_already_set')]);
        }

        $this->validate($request, [
            'one_time_password' => 'required',
        ]);

        //retrieve secret
        $secret = $request->session()->pull($this->SESSION_TFA_SECRET);

        $authenticator = app(Authenticator::class)->boot($request);

        if ($authenticator->verifyGoogle2FA($secret, $request['one_time_password'])) {
            //encrypt and then save secret
            $user->google2fa_secret = $secret;
            $user->save();

            $authenticator->login();

            return response()->json(['success' => true]);
        }

        $authenticator->logout();

        return response()->json(['success' => false]);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function disableTwoFactor(Request $request)
    {
        return view('settings.security.2fa-disable');
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function deactivateTwoFactor(Request $request)
    {
        $this->validate($request, [
            'one_time_password' => 'required',
        ]);

        $user = $request->user();

        //retrieve secret
        $secret = $user->google2fa_secret;

        $authenticator = app(Authenticator::class)->boot($request);

        if ($authenticator->verifyGoogle2FA($secret, $request['one_time_password'])) {

            //make secret column blank
            $user->google2fa_secret = null;
            $user->save();

            $authenticator->logout();

            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

    /**
     * Generate a secret key in Base32 format.
     *
     * @return string
     */
    private function generateSecret()
    {
        $google2fa = app('pragmarx.google2fa');

        return $google2fa->generateSecretKey(32);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function u2fRegister(Request $request)
    {
        list($req, $sigs) = app('u2f')->getRegisterData($request->user());
        session(['u2f.registerData' => $req]);

        return response()->json([
            'currentKeys' => $sigs,
            'registerData' => $req,
            ]);
    }

    /**
     * Generate recovery codes.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function recoveryCodes(Request $request)
    {
        // Remove previous codes
        $codes = auth()->user()->recoveryCodes()
                        ->each(function ($code) {
                            $code->delete();
                        });

        // Generate new codes
        $recovery = new PragmaRXRecovery();
        $codes = $recovery->setCount(config('auth.recovery.count'))
                 ->setBlocks(config('auth.recovery.blocks'))
                 ->setChars(config('auth.recovery.chars'))
                 ->uppercase()
                 ->toArray();

        foreach ($codes as $code) {
            RecoveryCode::create([
                'account_id' => auth()->user()->account_id,
                'user_id' => auth()->user()->id,
                'recovery' => $code,
            ]);
        }

        return response()->json([
            'codes' => $codes,
        ]);
    }
}
