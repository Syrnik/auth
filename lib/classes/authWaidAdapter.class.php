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
