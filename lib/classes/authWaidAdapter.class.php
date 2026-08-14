<?php

/**
 * Custom WAID OAuth adapter that routes callbacks through the auth app pipeline
 * (auth/callback/waid/) instead of the standard oauth.php handler.
 *
 * This ensures that guards, challenges and login events defined in the auth app
 * are applied uniformly for WAID logins, just like for email/phone methods.
 */
class authWaidAdapter extends waWebasystIDSiteAuth
{
    /**
     * Use auth app's callback route instead of oauth.php.
     * The redirect_uri sent to WAID auth center will be this URL,
     * so WAID will return the auth code here after the user authorizes.
     */
    public function getCallbackUrl($absolute = true): string
    {
        return authHelper::getCallbackUrl('waid');
    }

    /**
     * The base adapter accepts a callback with no state at all
     * (waWebasystIDAuthAdapter::verifyState(): `return !$state || ...`), which
     * makes a callback forgeable by anyone who can hand a visitor a crafted
     * auth/callback/waid/ URL carrying an attacker-controlled code. That was a
     * pre-existing way to get logged in as someone else; AUTH-50 turns the
     * same route into one that can also *link* an identity onto whichever
     * contact is signed in, which raises the stakes from "attacker session"
     * to "permanent write to the victim's account" — so this override closes
     * it: a callback is accepted only when its state matches the one this
     * class generated and stored for this exact class (getHealthyRedirectUri()
     * → generateState() both run through this subclass, so the key lines up
     * on both legs).
     */
    protected function verifyState()
    {
        $state    = (string)waRequest::get('state', '', waRequest::TYPE_STRING_TRIM);
        $expected = (string)wa()->getStorage()->get(get_class($this) . '/state');

        return $state !== '' && $expected !== '' && hash_equals($expected, $state);
    }

    /**
     * Exchange OAuth code for access token and return normalized user data.
     * Combines processAuthResponse() + getUserData() + WAID contact ID extraction.
     * Returns same format as waWebasystIDSiteAuth::auth() (callback branch only).
     *
     * @throws waWebasystIDAuthException
     * @throws waWebasystIDAccessDeniedAuthException
     * @throws waException
     */
    public function processCallback(): array
    {
        $auth_response = $this->processAuthResponse();
        $user_data     = $this->getUserData($auth_response);

        $m = new waWebasystIDAccessTokenManager();
        $token_info      = $m->extractTokenInfo($auth_response['access_token']);
        $waid_contact_id = $token_info['contact_id'];

        $photo_url = null;
        if (!empty($user_data['userpic_uploaded'])) {
            $photo_url = $user_data['userpic_original_crop'];
        }
        unset($user_data['userpic'], $user_data['userpic_uploaded'], $user_data['userpic_original_crop']);

        return array_merge($user_data, [
            'source'    => $this->getId(),   // 'webasystID'
            'source_id' => $waid_contact_id,
            'photo_url' => $photo_url,
            // Webasyst ID only exposes confirmed emails, so the address is safe
            // to link onto an existing local account (see authContactResolver).
            'email_verified' => true,
        ]);
    }
}
