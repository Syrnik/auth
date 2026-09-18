<?php

/**
 * Opaque-to-the-core state passed from authRecoveryProvider::start() to
 * verifyCode()/complete(). The core only ever reads $channel (to find which
 * provider issued it) and $token (to address the auth_password_recovery row)
 * — everything else a provider needs (a real or decoy contact_id, a code) is
 * looked up by that provider itself, through its own row in the shared
 * table, not carried on this object.
 *
 * See docs/adr/004-recovery-channels.md, decision 6, for why the two entry
 * points differ in where this handle lives: a link-based provider's token
 * travels in a mailed URL and is looked up in the DB (cross-session by
 * necessity — the link is opened on whatever device the visitor has the mail
 * client on); a code-based provider's handle lives in the session
 * (wa()->getStorage()) instead, and is never accepted from the client, so a
 * submitted code can't be routed to a different provider's (possibly weaker)
 * verifyCode().
 */
class authRecoveryHandle
{
    public string $channel;
    public string $token;

    public function __construct(string $channel, string $token)
    {
        $this->channel = $channel;
        $this->token   = $token;
    }
}
