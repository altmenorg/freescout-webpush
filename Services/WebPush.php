<?php

namespace Modules\WebPush\Services;

/**
 * Web Push for the installable app and desktop browsers, without the FreeScout mobile app. Pure PHP (OpenSSL),
 * no Composer dependency:
 *  - VAPID (RFC 8292): ES256 JWT signed with the server's key, generated on first use;
 *  - aes128gcm content encryption (RFC 8291 / 8188): only the subscribed phone can read the notification.
 * Storage (outside the web root, storage/app/webpush, chmod 600): vapid.json (keys) and push_subscriptions.json
 * (subscriptions, changed under push_subscriptions.lock).
 */
class WebPush
{
    const TTL = 86400;                               // an undelivered notification expires after 24h

    public static function dir()
    {
        $dir = storage_path('app/webpush');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        return $dir;
    }

    protected static function b64u($bin)
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    protected static function unb64u($str)
    {
        return base64_decode(strtr($str, '-_', '+/').str_repeat('=', (4 - strlen($str) % 4) % 4));
    }

    /** Written to a temporary file then renamed: a reader never sees a half-written file. */
    protected static function writeJson($file, $data)
    {
        $path = self::dir().'/'.$file;
        $tmp = $path.'.'.getmypid().'.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @chmod($tmp, 0600);
        rename($tmp, $path);
    }

    protected static function readJson($file)
    {
        $path = self::dir().'/'.$file;
        if (!is_file($path)) {
            return null;
        }
        return json_decode((string)file_get_contents($path), true);
    }

    /** Raw public key (65 bytes, uncompressed point) of an OpenSSL EC key. */
    protected static function rawPublic($key)
    {
        $d = openssl_pkey_get_details($key);
        return "\x04".str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    protected static function newEcKey()
    {
        return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    }

    /** Server VAPID keys ['private' => PEM, 'public' => base64url], created on first call. */
    public static function vapid()
    {
        $k = self::readJson('vapid.json');
        if (!$k || empty($k['private']) || empty($k['public'])) {
            $key = self::newEcKey();
            openssl_pkey_export($key, $pem);
            $k = ['private' => $pem, 'public' => self::b64u(self::rawPublic($key))];
            self::writeJson('vapid.json', $k);
        }
        return $k;
    }

    public static function publicKey()
    {
        return self::vapid()['public'];
    }

    /* ---------------------------------------------------------------- subscriptions */

    public static function subscriptions()
    {
        return self::readJson('push_subscriptions.json') ?: [];
    }

    /**
     * Read, change and write the subscriptions under one lock: two changes at the same time (a device subscribing while
     * a notification drops an expired one, two queue workers) could otherwise each write their own copy and lose the
     * other's change. $change receives the list and returns the new one, or null to leave the file untouched.
     */
    protected static function changeSubscriptions(callable $change)
    {
        $lock = fopen(self::dir().'/push_subscriptions.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            $new = $change(self::subscriptions());
            if ($new !== null) {
                self::writeJson('push_subscriptions.json', array_values($new));
            }
            return $new;
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public static function subscribe($user_id, $sub, $ua = '')
    {
        if (empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])
            || !preg_match('#^https://#', $sub['endpoint'])
        ) {
            return false;
        }
        self::changeSubscriptions(function ($all) use ($user_id, $sub, $ua) {
            $all = array_filter($all, function ($s) use ($sub) {
                return $s['endpoint'] !== $sub['endpoint'];
            });
            $all[] = [
                'user_id'  => (int)$user_id,
                'endpoint' => $sub['endpoint'],
                'p256dh'   => $sub['keys']['p256dh'],
                'auth'     => $sub['keys']['auth'],
                'ua'       => mb_substr((string)$ua, 0, 200),
                'created'  => date('Y-m-d H:i:s'),
            ];
            return $all;
        });
        return true;
    }

    /** Removes a subscription; with $user_id, only if it belongs to that agent. Returns whether one was removed. */
    public static function unsubscribe($endpoint, $user_id = null)
    {
        $removed = false;
        self::changeSubscriptions(function ($all) use ($endpoint, $user_id, &$removed) {
            $kept = array_filter($all, function ($s) use ($endpoint, $user_id) {
                return $s['endpoint'] !== $endpoint || ($user_id !== null && (int)$s['user_id'] !== (int)$user_id);
            });
            $removed = count($kept) != count($all);
            return $removed ? $kept : null;
        });
        return $removed;
    }

    /** Short id of a device, safe to show in a page (the endpoint is not needed to remove it). */
    public static function deviceId($endpoint)
    {
        return substr(hash('sha256', (string)$endpoint), 0, 16);
    }

    /** The agent's devices, newest first: id, label ("Chrome · Android"), date added, endpoint (to spot this device). */
    public static function devices($user_id)
    {
        $list = [];
        foreach (self::subscriptions() as $s) {
            if ((int)$s['user_id'] === (int)$user_id) {
                $list[] = [
                    'id'       => self::deviceId($s['endpoint']),
                    'label'    => self::describe($s['ua'] ?? ''),
                    'created'  => !empty($s['created']) ? date('c', strtotime($s['created'])) : null,
                    'endpoint' => $s['endpoint'],
                ];
            }
        }
        usort($list, function ($a, $b) {
            return strcmp((string)$b['created'], (string)$a['created']);
        });
        return $list;
    }

    /** Removes one of the agent's devices by its id. */
    public static function removeDevice($user_id, $id)
    {
        foreach (self::subscriptions() as $s) {
            if ((int)$s['user_id'] === (int)$user_id && hash_equals(self::deviceId($s['endpoint']), (string)$id)) {
                return self::unsubscribe($s['endpoint'], $user_id);
            }
        }
        return false;
    }

    /** "Browser · System" from a user agent, for the device list. */
    public static function describe($ua)
    {
        $browsers = ['EdgA?/' => 'Edge', 'SamsungBrowser' => 'Samsung Internet', 'OPR/' => 'Opera', 'Firefox|FxiOS' => 'Firefox',
            'CriOS|Chrome' => 'Chrome', 'Safari' => 'Safari'];
        $systems = ['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'CrOS' => 'ChromeOS', 'Windows' => 'Windows',
            'Mac OS X|Macintosh' => 'macOS', 'Linux' => 'Linux'];
        $pick = function ($table) use ($ua) {
            foreach ($table as $pattern => $name) {
                if (preg_match('#'.$pattern.'#', (string)$ua)) {
                    return $name;
                }
            }
            return null;
        };
        $parts = array_filter([$pick($browsers), $pick($systems)]);
        return $parts ? implode(' · ', $parts) : __('webpush::messages.unknown_device');
    }

    /* ---------------------------------------------------------------- sending */

    /** Sends ['title','body','url','tag'] to all of the agent's devices. Returns the number of accepted sends. */
    public static function sendToUser($user_id, array $payload)
    {
        return self::sendToUsers([[(int)$user_id, $payload]]);
    }

    /**
     * Sends several [user_id, payload] at once: every device of every agent in parallel (curl_multi), so that a slow
     * or silent push service no longer delays the others. Each payload gets the agent's number of open assigned
     * tickets ("badge_count", the dot on the installed app's icon). Expired subscriptions (404, 410) are dropped.
     * Returns the number of accepted sends.
     */
    public static function sendToUsers(array $items)
    {
        $subs = self::subscriptions();
        $jobs = [];
        foreach ($items as $item) {
            list($user_id, $payload) = $item;
            $payload['badge_count'] = self::badgeCount($user_id);
            foreach ($subs as $sub) {
                if ((int)$sub['user_id'] === (int)$user_id) {
                    $jobs[] = [$sub, $payload, (int)$user_id];
                }
            }
        }
        $ok = 0;
        foreach (self::sendBatch($jobs) as $i => $code) {
            list($sub, , $user_id) = $jobs[$i];
            if ($code >= 200 && $code < 300) {
                $ok++;
            } elseif ($code == 404 || $code == 410) {
                // subscription expired or revoked (app uninstalled, notifications turned off): drop it
                self::unsubscribe($sub['endpoint']);
            } else {
                \Log::warning('[WebPush] HTTP '.$code.' for user '.$user_id);
            }
        }
        return $ok;
    }

    /** Sends encrypted content to a subscription; returns the push service's HTTP code (0 on network/encryption failure). */
    public static function send(array $sub, array $payload)
    {
        return self::sendBatch([[$sub, $payload]])[0];
    }

    /** Sends [subscription, payload, …] jobs in parallel; returns their HTTP codes, in the same order (0: not sent). */
    protected static function sendBatch(array $jobs)
    {
        $codes = array_fill(0, count($jobs), 0);
        $multi = curl_multi_init();
        $handles = [];
        foreach ($jobs as $i => $job) {
            $ch = self::request($job[0], $job[1]);
            if ($ch) {
                $handles[$i] = $ch;
                curl_multi_add_handle($multi, $ch);
            }
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);
        foreach ($handles as $i => $ch) {
            $codes[$i] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        return $codes;
    }

    /** The encrypted, signed request for one subscription, or null (encryption or signature impossible). */
    protected static function request(array $sub, array $payload)
    {
        $body = self::encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sub['p256dh'], $sub['auth']);
        if ($body === null) {
            return null;
        }
        $jwt = self::vapidJwt($sub['endpoint']);
        if ($jwt === null) {
            return null;
        }
        $ch = curl_init($sub['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: vapid t='.$jwt.', k='.self::publicKey(),
                'Content-Encoding: aes128gcm',
                'Content-Type: application/octet-stream',
                'TTL: '.self::TTL,
                'Urgency: high',
            ],
        ]);
        return $ch;
    }

    /** Open tickets assigned to the agent, in the mailboxes they can see: the number on the installed app's icon. */
    public static function badgeCount($user_id)
    {
        try {
            $user = \App\User::find($user_id);
            if (!$user) {
                return 0;
            }
            return \App\Conversation::where('user_id', $user->id)
                ->where('state', \App\Conversation::STATE_PUBLISHED)
                ->where('status', \App\Conversation::STATUS_ACTIVE)
                ->whereIn('mailbox_id', $user->mailboxesIdsCanView())
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Encryption and signature self-test, without network or writing: encrypts a message for a key pair made on the
     * spot (as a browser would hold), and signs a VAPID token if the server keys already exist. Null when it works.
     */
    public static function selfTest()
    {
        $browser = self::newEcKey();
        if (!$browser) {
            return 'OpenSSL cannot create P-256 keys';
        }
        $body = self::encrypt('{"selftest":true}', self::b64u(self::rawPublic($browser)), self::b64u(random_bytes(16)));
        if ($body === null || strlen($body) !== 16 + 4 + 1 + 65 + strlen('{"selftest":true}') + 1 + 16) {
            return 'aes128gcm encryption failed';
        }
        if (is_file(storage_path('app/webpush/vapid.json')) && self::vapidJwt('https://push.example.com/selftest') === null) {
            return 'VAPID signature failed';
        }
        return null;
    }

    /** Path of FreeScout's root ("/", or "/helpdesk/" when installed in a subfolder): scope of the app and its worker. */
    public static function basePath()
    {
        $path = (string)parse_url(url('/'), PHP_URL_PATH);
        return '/'.trim($path, '/').($path !== '' && trim($path, '/') !== '' ? '/' : '');
    }

    /** VAPID ES256 JWT for the push service's origin. */
    protected static function vapidJwt($endpoint)
    {
        $p = parse_url($endpoint);
        $aud = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        $input = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            .'.'.self::b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => Settings::pushContact()], JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private(self::vapid()['private']);
        if (!$key || !openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }
        return $input.'.'.self::b64u(self::derToRaw($der));
    }

    /** ECDSA DER signature (SEQUENCE { INTEGER r, INTEGER s }) -> r || s, 32 bytes each (JWS format). */
    protected static function derToRaw($der)
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }

    protected static function hkdf($salt, $ikm, $info, $len)
    {
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        return substr(hash_hmac('sha256', $info."\x01", $prk, true), 0, $len);
    }

    /** aes128gcm encryption (RFC 8291) of the content for the subscription's p256dh key / auth secret. */
    protected static function encrypt($plaintext, $p256dh_b64u, $auth_b64u)
    {
        $ua_public = self::unb64u($p256dh_b64u);
        $auth = self::unb64u($auth_b64u);
        if (strlen($ua_public) !== 65 || strlen($auth) < 16) {
            return null;
        }
        // phone's public key -> OpenSSL object (SubjectPublicKeyInfo P-256)
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$ua_public;
        $ua_key = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n");
        $as_key = self::newEcKey(); // ephemeral key, one per message
        if (!$ua_key || !$as_key) {
            return null;
        }
        $as_public = self::rawPublic($as_key);
        $ecdh = openssl_pkey_derive($ua_key, $as_key, 32);
        if ($ecdh === false) {
            return null;
        }
        $ikm = self::hkdf($auth, $ecdh, "WebPush: info\0".$ua_public.$as_public, 32);
        $salt = random_bytes(16);
        $cek = self::hkdf($salt, $ikm, "Content-Encoding: aes128gcm\0", 16);
        $nonce = self::hkdf($salt, $ikm, "Content-Encoding: nonce\0", 12);
        $cipher = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            return null;
        }
        // header: salt (16) | record size (4, 4096) | key id length (1) | ephemeral public key (65)
        return $salt.pack('N', 4096).chr(65).$as_public.$cipher.$tag;
    }
}
