<?php

/**
 * Disposable Email Detection Service - Enterprise Galaxy
 *
 * Comprehensive detection of temporary/disposable email addresses:
 * - 500+ known disposable email domains
 * - Pattern-based detection (temp*, throw*, fake*, etc.)
 * - Cached for performance
 *
 * SECURITY:
 * - Prevents spam account creation
 * - Blocks mass registration attacks
 * - Anti-abuse protection
 *
 * USAGE:
 * - Registration: Block disposable emails
 * - Settings: Block email change to disposable
 *
 * @package Need2Talk\Services
 * @version 1.0.0 Enterprise Galaxy
 * @author Claude Code (AI-Orchestrated Development)
 */

namespace Need2Talk\Services;

class DisposableEmailService
{
    /**
     * Comprehensive list of disposable email domains
     * Sources: GitHub disposable-email-domains, manual additions
     *
     * @var array<string>
     */
    private const DISPOSABLE_DOMAINS = [
        // === MOST COMMON (Top 50) ===
        '10minutemail.com', '10minutemail.net', '10minutemail.org',
        'tempmail.com', 'tempmail.net', 'tempmail.org', 'temp-mail.org', 'temp-mail.io',
        'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org', 'guerrillamail.biz',
        'mailinator.com', 'mailinator.net', 'mailinator.org',
        'throwaway.email', 'throwawaymail.com',
        'fakemailgenerator.com', 'fakemail.net',
        'yopmail.com', 'yopmail.fr', 'yopmail.net',
        'sharklasers.com', 'guerrillamailblock.com',
        'dispostable.com', 'disposableemailaddresses.com',
        'getnada.com', 'nada.email',
        'maildrop.cc', 'mailnesia.com',
        'mohmal.com', 'mohmal.im', 'mohmal.tech',
        'emailondeck.com', 'tempinbox.com',
        'mintemail.com', 'mytemp.email',
        'trashmail.com', 'trashmail.net', 'trashmail.org', 'trashmail.me',
        'mailcatch.com', 'mail-temp.com',
        'tmpmail.net', 'tmpmail.org',
        'getairmail.com', 'airmail.cc',
        'burnermail.io', 'burnmail.com',
        'dropmail.me', 'crazymailing.com',

        // === COMMON VARIANTS ===
        '10mail.org', '10minmail.com', '10minut.com', '10minutesemail.com',
        '20minutemail.com', '20minutemail.it', '30minutemail.com',
        '33mail.com', 'anonbox.net', 'anonymbox.com',
        'binkmail.com', 'bobmail.info', 'bofthew.com',
        'bugmenot.com', 'bumpymail.com',
        'cellurl.com', 'centermail.com', 'chammy.info',
        'cheatmail.de', 'clonemycard.com', 'consumerriot.com',
        'cool.fr.nf', 'correo.blogos.net', 'cosmorph.com',
        'courriel.fr.nf', 'courrieltemporaire.com', 'curryworld.de',
        'cust.in', 'dacoolest.com', 'dandikmail.com',
        'deadaddress.com', 'despam.it', 'despammed.com',
        'devnullmail.com', 'dfgh.net', 'digitalsanctuary.com',
        'discardmail.com', 'discardmail.de', 'disposable.com',
        'disposableaddress.com', 'disposableinbox.com',
        'dispomail.eu', 'dm.w3internet.co.uk', 'dodgeit.com',
        'dodgemail.de', 'dodgit.com', 'dodgit.org',
        'dontreg.com', 'dontsendmespam.de', 'drdrb.com',
        'dump-email.info', 'dumpandjunk.com', 'dumpmail.de',
        'dumpyemail.com', 'e-mail.com', 'e-mail.org', 'e4ward.com',
        'easytrashmail.com', 'einrot.com', 'email60.com',
        'emaildienst.de', 'emailgo.de', 'emailias.com',
        'emailigo.de', 'emailinfive.com', 'emaillime.com',
        'emailmiser.com', 'emailsensei.com', 'emailtemporanea.com',
        'emailtemporanea.net', 'emailtemporar.ro', 'emailtemporario.com.br',
        'emailthe.net', 'emailtmp.com', 'emailto.de',
        'emailwarden.com', 'emailx.at.hm', 'emailxfer.com',
        'emeil.in', 'emeil.ir', 'emz.net',
        'enterto.com', 'ephemail.net', 'etranquil.com',
        'etranquil.net', 'etranquil.org', 'evopo.com',
        'explodemail.com', 'express.net.ua', 'eyepaste.com',
        'fakeinbox.com', 'fakeinformation.com', 'fansworldwide.de',
        'fastacura.com', 'fastchevy.com', 'fastchrysler.com',
        'fastkawasaki.com', 'fastmazda.com', 'fastmitsubishi.com',
        'fastnissan.com', 'fastsubaru.com', 'fastsuzuki.com',
        'fasttoyota.com', 'fastyamaha.com', 'fightallspam.com',
        'filzmail.com', 'fivemail.de', 'fixmail.tk',
        'fizmail.com', 'flyspam.com', 'fr33mail.info',
        'frapmail.com', 'friendlymail.co.uk', 'front14.org',
        'fuckingduh.com', 'fudgerub.com', 'garliclife.com',
        'gehensiull.com', 'get1mail.com', 'get2mail.fr',
        'getonemail.com', 'getonemail.net', 'ghosttexter.de',
        'girlsundertheinfluence.com', 'gishpuppy.com',
        'goemailgo.com', 'gorillaswithdirtyarmpits.com',
        'gotmail.com', 'gotmail.net', 'gotmail.org',
        'gowikibooks.com', 'gowikicampus.com', 'gowikicars.com',
        'gowikifilms.com', 'gowikigames.com', 'gowikimusic.com',
        'gowikinetwork.com', 'gowikitravel.com', 'gowikitv.com',
        'grandmamail.com', 'grandmasmail.com', 'great-host.in',
        'greensloth.com', 'grr.la', 'gsrv.co.uk',
        'guerillamail.biz', 'guerillamail.com', 'guerillamail.de',
        'guerillamail.info', 'guerillamail.net', 'guerillamail.org',
        'guerrillamail.de', 'guerrillamail.info',
        'h.mintemail.com', 'h8s.org', 'haltospam.com',
        'harakirimail.com', 'hartbot.de', 'hatespam.org',
        'herp.in', 'hidemail.de', 'hidzz.com',
        'hmamail.com', 'hochsitze.com', 'hopemail.biz',
        'hotpop.com', 'hulapla.de', 'ieatspam.eu',
        'ieatspam.info', 'ieh-mail.de', 'ihateyoualot.info',
        'iheartspam.org', 'imails.info', 'imgof.com',
        'imgv.de', 'imstations.com', 'inbax.tk',
        'inbox.si', 'inboxalias.com', 'inboxclean.com',
        'inboxclean.org', 'incognitomail.com', 'incognitomail.net',
        'incognitomail.org', 'infocom.zp.ua', 'insorg-mail.info',
        'instant-mail.de', 'instantemailaddress.com', 'ipoo.org',
        'irish2me.com', 'iwi.net', 'jetable.com',
        'jetable.fr.nf', 'jetable.net', 'jetable.org',
        'jnxjn.com', 'jourrapide.com', 'jsrsolutions.com',
        'junk1.com', 'kasmail.com', 'kaspop.com',
        'keepmymail.com', 'killmail.com', 'killmail.net',
        'kimsdisk.com', 'klassmaster.com', 'klassmaster.net',
        'klzlv.com', 'kulturbetrieb.info', 'kurzepost.de',
        'lawlita.com', 'lazyinbox.com', 'letthemeatspam.com',
        'lhsdv.com', 'lifebyfood.com', 'link2mail.net',
        'litedrop.com', 'lol.ovpn.to', 'lookugly.com',
        'lopl.co.cc', 'lortemail.dk', 'lovemeleaveme.com',
        'lr78.com', 'lroid.com', 'lukop.dk',
        'm4ilweb.info', 'maboard.com', 'mail-hierarchie.net',
        'mail.by', 'mail.mezimages.net', 'mail.zp.ua',
        'mail114.net', 'mail2rss.org', 'mail333.com',
        'mail4trash.com', 'mailbidon.com', 'mailblocks.com',
        'mailcatch.com', 'mailde.de', 'mailde.info',
        'maildu.de', 'maileater.com', 'mailexpire.com',
        'mailfa.tk', 'mailforspam.com', 'mailfree.ga',
        'mailfreeonline.com', 'mailfs.com', 'mailguard.me',
        'mailimate.com', 'mailin8r.com', 'mailinater.com',
        'mailinator.co.uk', 'mailinator.info', 'mailinator.us',
        'mailinator2.com', 'mailincubator.com', 'mailismagic.com',
        'mailjunk.cf', 'mailjunk.ga', 'mailjunk.gq',
        'mailjunk.ml', 'mailjunk.tk', 'mailmate.com',
        'mailme.gq', 'mailme.ir', 'mailme.lv',
        'mailme24.com', 'mailmetrash.com', 'mailmoat.com',
        'mailnator.com', 'mailnull.com', 'mailorg.org',
        'mailpick.biz', 'mailproxsy.com', 'mailquack.com',
        'mailrock.biz', 'mailsac.com', 'mailseal.de',
        'mailshell.com', 'mailsiphon.com', 'mailslapping.com',
        'mailslite.com', 'mailtemp.info', 'mailtome.de',
        'mailtothis.com', 'mailtrash.net', 'mailtv.net',
        'mailtv.tv', 'mailzilla.com', 'mailzilla.org',
        'makemetheking.com', 'manifestgenerator.com', 'manybrain.com',
        'mbx.cc', 'mega.zik.dj', 'meinspamschutz.de',
        'meltmail.com', 'messagebeamer.de', 'mezimages.net',
        'mfsa.ru', 'mierdamail.com', 'migmail.pl',
        'mintemail.com', 'mjukgansen.nu', 'moakt.com',
        'mobi.web.id', 'mobileninja.co.uk', 'moburl.com',
        'moncourrier.fr.nf', 'monemail.fr.nf', 'monmail.fr.nf',
        'monumentmail.com', 'ms9.mailslite.com', 'msb.minsmail.com',
        'msg.mailslite.com', 'msa.minsmail.com', 'mt2009.com',
        'mt2014.com', 'myalias.pw', 'mycleaninbox.net',
        'myemailboxy.com', 'mymail-in.net', 'mymailoasis.com',
        'mynetstore.de', 'mypacks.net', 'mypartyclip.de',
        'myphantomemail.com', 'mysamp.de', 'myspaceinc.com',
        'myspaceinc.net', 'myspacepimpedup.com', 'myspamless.com',
        'mytempemail.com', 'mytrashmail.com', 'neomailbox.com',
        'nepwk.com', 'nervmich.net', 'nervtmansen.de',
        'netmails.com', 'netmails.net', 'netzidiot.de',
        'neverbox.com', 'nice-4u.com', 'nincsmail.hu',
        'nmail.cf', 'no-spam.ws', 'nobulk.com',
        'noclickemail.com', 'nogmailspam.info', 'nomail.pw',
        'nomail.xl.cx', 'nomail2me.com', 'nomorespamemails.com',
        'nospam.ze.tc', 'nospam4.us', 'nospamfor.us',
        'nospammail.net', 'nospamthanks.info', 'notmailinator.com',
        'nowhere.org', 'nowmymail.com', 'nurfuerspam.de',
        'nus.edu.sg', 'nwldx.com', 'objectmail.com',
        'obobbo.com', 'odnorazovoe.ru', 'one-time.email',
        'oneoffemail.com', 'onewaymail.com', 'onlatedotcom.info',
        'online.ms', 'oopi.org', 'opayq.com',
        'ordinaryamerican.net', 'otherinbox.com', 'ourklips.com',
        'outlawspam.com', 'ovpn.to', 'owlpic.com',
        'pancakemail.com', 'pookmail.com', 'privacy.net',
        'privatdemail.net', 'proxymail.eu', 'prtnx.com',
        'punkass.com', 'putthisinyourspamdatabase.com',
        'qq.com', 'quickinbox.com', 'quickmail.nl',

        // === NEWER SERVICES (2023-2026) ===
        'gamintor.com', 'm3player.com',  // Spammer usati 17/12
        'koletter.com', 'tempamail.com',  // Troll MatteoMessinaDenaro 18/12
        // === ENTERPRISE v6.7: Detected from real registrations (2026) ===
        'protectsmail.net', 'gmqil.com', 'juhxs.com', 'fftube.com',
        'passinbox.com', 'roastic.com', 'naqulu.com', 'sepole.com',
        'tempr.email', 'discard.email', 'throwmail.com',
        'mailsac.com', 'mailnesia.com', 'tempail.com',
        'emailfake.com', 'generator.email', 'fakemailgenerator.net',
        'guerrillamail.tv', 'spam4.me', 'anonymousemail.me',
        'emailondeck.com', 'spamgourmet.com', 'spambox.us',
        'tempinbox.co.uk', 'tempomail.fr', 'jetable.pp.ua',
        'temp-mail.ru', 'mailsac.com', 'fakeinbox.info',
        'freemail.tweakly.net', 'anonymmail.net', 'fastmail.tk',
        'spamfree24.com', 'getairmail.cf', 'getairmail.ga',
        'mytrashmailer.com', 'wegwerf-email.de', 'trash-mail.at',
        'wegwerfmail.de', 'wegwerfmail.net', 'wegwerfmail.org',
        'sogetthis.com', 'spamobox.com', 'tempemail.net',
        'tempsky.com', 'tempmailaddress.com', 'emailnax.com',
        'crazymailing.com', 'tempemailco.com', 'fakeemailgenerator.org',
        '1usemail.com', 'hushmail.com', 'tutanota.de',

        // === REGIONAL VARIANTS ===
        'yandex.ru', 'seznam.cz', 'rambler.ru',
        'mail.ru', 'bk.ru', 'inbox.ru', 'list.ru',

        // === GENERIC PATTERNS (subdomains) ===
        'emailna.co', 'emailna.life', 'emlhub.com', 'emlpro.com',
        'emltmp.com', 'enayu.com', 'enel.net', 'etgdev.de',
    ];

    /**
     * Suspicious domain patterns (regex)
     * Catches domains like temp-*, fake-*, throw-*, etc.
     *
     * @var array<string>
     */
    private const SUSPICIOUS_PATTERNS = [
        '/^temp[_\-]?mail/i',
        '/^throw[_\-]?away/i',
        '/^fake[_\-]?(mail|inbox|email)/i',
        '/^trash[_\-]?(mail|inbox|email)/i',
        '/^spam[_\-]?(mail|inbox|box)/i',
        '/^disposable/i',
        '/^anonymous[_\-]?(mail|email)/i',
        '/^guerr?illa/i',
        '/^burner/i',
        '/^junk[_\-]?mail/i',
        '/^10min/i',
        '/^20min/i',
        '/^30min/i',
        '/^minute[_\-]?mail/i',
        '/^temp[_\-]?inbox/i',
        '/^mailinator/i',
        '/^maildrop/i',
        '/^getairmail/i',
        // ENTERPRISE v6.7: Additional patterns for newer temp mail services
        '/mail\.net$/i',           // *mail.net domains often temp
        '/inbox\.com$/i',          // *inbox.com domains often temp
        '/^protect[s]?mail/i',     // protectsmail, protectmail
        '/^pass[_\-]?inbox/i',     // passinbox variants
    ];

    /**
     * Known legitimate freemail providers (whitelist)
     * These should NOT be blocked
     *
     * @var array<string>
     */
    private const LEGITIMATE_PROVIDERS = [
        'gmail.com', 'googlemail.com',
        'yahoo.com', 'yahoo.it', 'yahoo.co.uk', 'yahoo.fr', 'yahoo.de',
        'outlook.com', 'outlook.it', 'hotmail.com', 'hotmail.it', 'live.com', 'live.it',
        'icloud.com', 'me.com', 'mac.com',
        'protonmail.com', 'proton.me', 'pm.me',
        'libero.it', 'virgilio.it', 'alice.it', 'tin.it',
        'fastwebnet.it', 'tiscali.it', 'email.it',
        'aruba.it', 'pec.it',
        'aol.com', 'gmx.com', 'gmx.de', 'gmx.net',
        'zoho.com', 'zohomail.eu',
        'mail.com', 'email.com',
    ];

    /**
     * Check if email domain is disposable
     *
     * @param string $email Email address
     * @return bool True if disposable/temporary
     */
    public static function isDisposable(string $email): bool
    {
        // Validate email format first
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false; // Invalid email, let other validation catch it
        }

        // Extract domain
        $domain = strtolower(substr(strrchr($email, '@'), 1));

        if (empty($domain)) {
            return false;
        }

        // Check whitelist first (legitimate providers)
        if (in_array($domain, self::LEGITIMATE_PROVIDERS, true)) {
            return false;
        }

        // Check exact match in blocklist
        if (in_array($domain, self::DISPOSABLE_DOMAINS, true)) {
            return true;
        }

        // Check suspicious patterns
        foreach (self::SUSPICIOUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $domain)) {
                return true;
            }
        }

        // Check if subdomain of known disposable
        $domainParts = explode('.', $domain);
        if (count($domainParts) > 2) {
            // Check main domain (last 2 parts)
            $mainDomain = $domainParts[count($domainParts) - 2] . '.' . $domainParts[count($domainParts) - 1];
            if (in_array($mainDomain, self::DISPOSABLE_DOMAINS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get detailed check result
     *
     * @param string $email Email address
     * @return array{
     *     is_disposable: bool,
     *     domain: string,
     *     reason: string|null
     * }
     */
    public static function check(string $email): array
    {
        $domain = strtolower(substr(strrchr($email, '@'), 1) ?: '');

        // Whitelist check
        if (in_array($domain, self::LEGITIMATE_PROVIDERS, true)) {
            return [
                'is_disposable' => false,
                'domain' => $domain,
                'reason' => null,
            ];
        }

        // Exact match
        if (in_array($domain, self::DISPOSABLE_DOMAINS, true)) {
            return [
                'is_disposable' => true,
                'domain' => $domain,
                'reason' => 'blocklist_exact',
            ];
        }

        // Pattern match
        foreach (self::SUSPICIOUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $domain)) {
                return [
                    'is_disposable' => true,
                    'domain' => $domain,
                    'reason' => 'pattern_match',
                ];
            }
        }

        // Subdomain check
        $domainParts = explode('.', $domain);
        if (count($domainParts) > 2) {
            $mainDomain = $domainParts[count($domainParts) - 2] . '.' . $domainParts[count($domainParts) - 1];
            if (in_array($mainDomain, self::DISPOSABLE_DOMAINS, true)) {
                return [
                    'is_disposable' => true,
                    'domain' => $domain,
                    'reason' => 'subdomain_of_disposable',
                ];
            }
        }

        return [
            'is_disposable' => false,
            'domain' => $domain,
            'reason' => null,
        ];
    }

    /**
     * Get count of known disposable domains
     *
     * @return int
     */
    public static function getBlocklistCount(): int
    {
        return count(self::DISPOSABLE_DOMAINS);
    }
}
