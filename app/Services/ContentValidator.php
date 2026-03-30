<?php

namespace Need2Talk\Services;

/**
 * ContentValidator - Sistema di validazione contenuti
 *
 * Funzionalità:
 * - Filtro parole offensive in italiano
 * - Validazione lunghezza testo
 * - Rilevamento spam patterns
 * - Pulizia HTML/XSS
 * - Supporto per descrizioni audio, commenti, nickname
 */
class ContentValidator
{
    private array $profanityWords;

    private array $spamPatterns;

    private int $maxNicknameLength = 50;

    private int $maxDescriptionLength = 1000;

    private int $maxCommentLength = 500;

    public function __construct()
    {
        $this->loadProfanityList();
        $this->loadSpamPatterns();
    }

    /**
     * Validazione completa per nickname utente
     */
    public function validateNickname(string $nickname): array
    {
        $result = ['valid' => true, 'errors' => [], 'cleaned' => $nickname];

        // Pulizia base
        $cleaned = trim($nickname);

        // Lunghezza
        if (strlen($cleaned) < 3) {
            $result['errors'][] = 'Il nickname deve essere di almeno 3 caratteri';
            $result['valid'] = false;
        }

        if (strlen($cleaned) > $this->maxNicknameLength) {
            $result['errors'][] = "Il nickname non può superare {$this->maxNicknameLength} caratteri";
            $result['valid'] = false;
        }

        // Caratteri permessi
        if (!preg_match('/^[a-zA-Z0-9_\-àáâãäåæçèéêëìíîïðñòóôõöøùúûüýþÿ]+$/u', $cleaned)) {
            $result['errors'][] = 'Il nickname può contenere solo lettere, numeri, underscore e trattini';
            $result['valid'] = false;
        }

        // Profanity check
        $profanityCheck = $this->checkProfanity($cleaned);

        if (!$profanityCheck['clean']) {
            $result['errors'][] = 'Il nickname contiene parole non appropriate';
            $result['valid'] = false;
        }

        // ENTERPRISE V4.9: Strict staff impersonation check
        // Block nicknames that could mislead users into thinking they're official staff
        $staffImpersonationCheck = $this->checkStaffImpersonation($cleaned);

        if (!$staffImpersonationCheck['allowed']) {
            $result['errors'][] = $staffImpersonationCheck['reason'];
            $result['valid'] = false;
        }

        // Parole riservate esatte
        $reserved = ['api', 'www', 'mail', 'ftp', 'smtp', 'imap', 'pop3', 'ns1', 'ns2'];

        if (in_array(strtolower($cleaned), $reserved, true)) {
            $result['errors'][] = 'Questo nickname è riservato per uso tecnico';
            $result['valid'] = false;
        }

        $result['cleaned'] = $cleaned;

        return $result;
    }

    /**
     * Validazione descrizione audio
     */
    public function validateAudioDescription(string $description): array
    {
        $result = ['valid' => true, 'errors' => [], 'cleaned' => $description];

        // Pulizia HTML e XSS
        $cleaned = $this->sanitizeHtml($description);

        // Lunghezza
        if (strlen($cleaned) > $this->maxDescriptionLength) {
            $result['errors'][] = "La descrizione non può superare {$this->maxDescriptionLength} caratteri";
            $result['valid'] = false;
        }

        // Profanity check
        $profanityCheck = $this->checkProfanity($cleaned);

        if (!$profanityCheck['clean']) {
            $result['errors'][] = 'La descrizione contiene linguaggio inappropriato';
            $result['cleaned'] = $profanityCheck['filtered'];
            $result['warnings'][] = 'Alcune parole sono state filtrate automaticamente';
        }

        // Spam check
        $spamCheck = $this->checkSpam($cleaned);

        if (!$spamCheck['clean']) {
            $result['errors'][] = $spamCheck['reason'];
            $result['valid'] = false;
        }

        // URL check - limita link esterni
        if ($this->containsExternalLinks($cleaned)) {
            $result['warnings'][] = 'Link esterni rilevati - soggetti a moderazione';
        }

        $result['cleaned'] = $cleaned;

        return $result;
    }

    /**
     * Validazione commenti
     */
    public function validateComment(string $comment): array
    {
        $result = ['valid' => true, 'errors' => [], 'cleaned' => $comment];

        // Pulizia HTML
        $cleaned = $this->sanitizeHtml($comment);

        // Lunghezza minima
        if (strlen(trim($cleaned)) < 3) {
            $result['errors'][] = 'Il commento deve essere di almeno 3 caratteri';
            $result['valid'] = false;
        }

        // Lunghezza massima
        if (strlen($cleaned) > $this->maxCommentLength) {
            $result['errors'][] = "Il commento non può superare {$this->maxCommentLength} caratteri";
            $result['valid'] = false;
        }

        // Profanity check - più permissivo per commenti
        $profanityCheck = $this->checkProfanity($cleaned, 'moderate');

        if (!$profanityCheck['clean']) {
            $result['cleaned'] = $profanityCheck['filtered'];
            $result['warnings'][] = 'Alcune parole sono state filtrate';
        }

        // Spam check
        $spamCheck = $this->checkSpam($cleaned);

        if (!$spamCheck['clean']) {
            $result['errors'][] = $spamCheck['reason'];
            $result['valid'] = false;
        }

        $result['cleaned'] = $cleaned;

        return $result;
    }

    /**
     * API per JavaScript - Validazione in tempo reale
     */
    public static function validateForApi(string $content, string $type): array
    {
        $validator = new self();

        switch ($type) {
            case 'nickname':
                return $validator->validateNickname($content);
            case 'description':
                return $validator->validateAudioDescription($content);
            case 'comment':
                return $validator->validateComment($content);
            case 'title':
                return $validator->validateTitle($content);
            default:
                return ['valid' => false, 'errors' => ['Tipo di validazione non supportato']];
        }
    }

    /**
     * Validazione titoli audio - Enterprise method
     */
    public function validateTitle(string $title): array
    {
        $result = ['valid' => true, 'errors' => [], 'cleaned' => $title];

        // Pulizia base
        $cleaned = trim($title);

        // Verifica lunghezza
        if (strlen($cleaned) < 3) {
            $result['errors'][] = 'Il titolo deve essere di almeno 3 caratteri';
            $result['valid'] = false;
        }

        if (strlen($cleaned) > 200) {
            $result['errors'][] = 'Il titolo non può superare 200 caratteri';
            $result['valid'] = false;
        }

        // Controllo profanity
        $profanityResult = $this->checkProfanity($cleaned);

        if (!$profanityResult['clean']) {
            $result['errors'][] = 'Il titolo contiene contenuti non appropriati';
            $result['valid'] = false;
            $cleaned = $profanityResult['filtered'];
        }

        // Controllo spam
        $spamResult = $this->checkSpam($cleaned);

        if (!$spamResult['clean']) {
            $result['errors'][] = 'Il titolo sembra contenere spam: ' . $spamResult['reason'];
            $result['valid'] = false;
        }

        // Pulizia HTML/XSS
        $cleaned = $this->sanitizeHtml($cleaned);

        $result['cleaned'] = $cleaned;

        return $result;
    }

    /**
     * ENTERPRISE V4.9: Check for staff impersonation in nicknames
     *
     * Blocks nicknames that could mislead users into thinking they're official staff.
     * Matches patterns like:
     * - "need2talk_admin", "admin_need2talk", "n2t_staff"
     * - "support", "staff", "team", "moderator", "mod"
     * - "official", "verified", "help", "helpdesk"
     */
    private function checkStaffImpersonation(string $nickname): array
    {
        $lower = strtolower($nickname);

        // Brand-related keywords that imply official status
        $brandKeywords = [
            'need2talk',
            'n2t',
            'n2talk',
            'need2',
            'needtotalk',
        ];

        // Staff role keywords
        $staffKeywords = [
            'admin',
            'administrator',
            'staff',
            'team',
            'support',
            'supporto',
            'help',
            'helpdesk',
            'moderator',
            'moderatore',
            'mod',
            'official',
            'ufficiale',
            'verified',
            'verificato',
            'founder',
            'fondatore',
            'ceo',
            'owner',
            'proprietario',
            'developer',
            'sviluppatore',
            'dev',
            'system',
            'sistema',
            'bot',
            'service',
            'servizio',
        ];

        // Check 1: Exact match with brand
        foreach ($brandKeywords as $brand) {
            if ($lower === $brand) {
                return [
                    'allowed' => false,
                    'reason' => 'Questo nickname è riservato. Non puoi usare il nome del brand.',
                ];
            }
        }

        // Check 2: Exact match with staff keywords
        foreach ($staffKeywords as $staff) {
            if ($lower === $staff) {
                return [
                    'allowed' => false,
                    'reason' => 'Questo nickname è riservato. Scegli un nome diverso.',
                ];
            }
        }

        // Check 3: Brand + staff combination (e.g., "need2talk_admin", "staff_n2t")
        foreach ($brandKeywords as $brand) {
            if (strpos($lower, $brand) !== false) {
                // Contains brand, now check for staff keywords
                foreach ($staffKeywords as $staff) {
                    if (strpos($lower, $staff) !== false) {
                        return [
                            'allowed' => false,
                            'reason' => 'Non puoi usare combinazioni che sembrano account ufficiali dello staff.',
                        ];
                    }
                }
            }
        }

        // Check 4: Patterns that look official regardless of brand
        // e.g., "official_john", "john_admin", "support_team", "mod_user"
        $officialPatterns = [
            '/^(official|ufficiale)[_\-]?/',          // Starts with official
            '/[_\-]?(official|ufficiale)$/',          // Ends with official
            '/^(admin|administrator)[_\-]?/',         // Starts with admin
            '/^(staff|team|support|supporto)[_\-]?/', // Starts with staff words
            '/[_\-]?(admin|staff|team|support)$/',    // Ends with staff words
            '/^(mod|moderator|moderatore)[_\-]?/',    // Starts with mod
            '/[_\-]?(mod|moderator|moderatore)$/',    // Ends with mod
            '/^(help|helpdesk)[_\-]?/',               // Starts with help
            '/[_\-]?(help|helpdesk)$/',               // Ends with help
            '/^(verified|verificato)[_\-]?/',         // Starts with verified
            '/[_\-]?(verified|verificato)$/',         // Ends with verified
            '/^(system|sistema|bot|service)[_\-]?/',  // Starts with system words
        ];

        foreach ($officialPatterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                return [
                    'allowed' => false,
                    'reason' => 'Questo nickname sembra un account ufficiale. Scegli un nome diverso.',
                ];
            }
        }

        // Check 5: Leetspeak and obfuscation attempts
        // e.g., "4dm1n", "st4ff", "supp0rt", "m0d"
        $obfuscatedText = $this->deLeetspeak($lower);

        if ($obfuscatedText !== $lower) {
            // Re-run checks on de-obfuscated text
            foreach ($staffKeywords as $staff) {
                if ($obfuscatedText === $staff || strpos($obfuscatedText, $staff) !== false) {
                    return [
                        'allowed' => false,
                        'reason' => 'Non puoi usare varianti di parole riservate.',
                    ];
                }
            }
        }

        return ['allowed' => true, 'reason' => ''];
    }

    /**
     * Convert leetspeak to normal text for detection
     */
    private function deLeetspeak(string $text): string
    {
        $replacements = [
            '0' => 'o',
            '1' => 'i',
            '3' => 'e',
            '4' => 'a',
            '5' => 's',
            '6' => 'g',
            '7' => 't',
            '8' => 'b',
            '9' => 'g',
            '@' => 'a',
            '$' => 's',
            '!' => 'i',
            '|' => 'l',
        ];

        return strtr($text, $replacements);
    }

    /**
     * Controllo parole offensive
     */
    private function checkProfanity(string $text, string $level = 'strict'): array
    {
        $text = strtolower($text);
        $foundWords = [];

        $wordsToCheck = $level === 'strict'
            ? $this->profanityWords
            : array_slice($this->profanityWords, 0, count($this->profanityWords) / 2); // Solo le più offensive

        foreach ($wordsToCheck as $word) {
            if (strpos($text, $word) !== false) {
                $foundWords[] = $word;
            }
        }

        $filtered = $text;

        foreach ($foundWords as $word) {
            $asterisks = str_repeat('*', strlen($word));
            $filtered = str_replace($word, $asterisks, $filtered);
        }

        return [
            'clean' => empty($foundWords),
            'found_words' => $foundWords,
            'filtered' => $filtered,
        ];
    }

    /**
     * Controllo pattern spam
     */
    private function checkSpam(string $text): array
    {
        foreach ($this->spamPatterns as $pattern => $reason) {
            if (preg_match($pattern, $text)) {
                return ['clean' => false, 'reason' => $reason];
            }
        }

        // Check ripetizioni eccessive
        if (preg_match('/(.)\1{10,}/', $text)) {
            return ['clean' => false, 'reason' => 'Troppe ripetizioni di caratteri'];
        }

        // Check CAPS LOCK eccessivo
        $uppercaseRatio = strlen(preg_replace('/[^A-Z]/', '', $text)) / strlen($text);

        if (strlen($text) > 20 && $uppercaseRatio > 0.7) {
            return ['clean' => false, 'reason' => 'Troppo testo in maiuscolo'];
        }

        return ['clean' => true, 'reason' => ''];
    }

    /**
     * Sanitizza HTML e previene XSS
     */
    private function sanitizeHtml(string $text): string
    {
        // Rimuovi tag HTML completamente
        $text = strip_tags($text);

        // Pulisci caratteri speciali
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalizza spazi
        $text = preg_replace('/\s+/', ' ', trim($text));

        return $text;
    }

    /**
     * Rileva link esterni
     */
    private function containsExternalLinks(string $text): bool
    {
        $urlPattern = '/(?:(?:https?|ftp):\/\/|www\.)[^\s]+/i';

        return preg_match($urlPattern, $text);
    }

    /**
     * Carica lista parole offensive in italiano
     *
     * ENTERPRISE V10.83: Vocabolario esteso con categorie specifiche:
     * - Parole volgari comuni
     * - Termini pornografici
     * - Termini per incontri/hookup
     * - Termini denigratori verso le donne (misoginia)
     * - Termini anatomici maschili volgari
     * - Insulti e discriminazioni
     */
    private function loadProfanityList(): void
    {
        $this->profanityWords = [
            // ============================================================
            // PAROLE VOLGARI COMUNI
            // ============================================================
            'merda', 'cazzo', 'culo', 'puttana', 'troia', 'coglione', 'stronzo',
            'bastardo', 'figa', 'sborra', 'inculare', 'scopare', 'pompino',
            'sega', 'pisello', 'minchia', 'porco', 'madonna', 'dio cane',
            'porco dio', 'dio merda', 'cristo', 'gesù cristo',
            'vaffanculo', 'affanculo', 'testa di cazzo', 'pezzo di merda',
            'figlio di puttana', 'cornuto', 'rompicoglioni',

            // ============================================================
            // TERMINI PORNOGRAFICI E ATTI SESSUALI
            // ============================================================
            'porno', 'porn', 'xxx', 'sesso', 'sex', 'sexy',
            'scopata', 'scopami', 'scopiamo', 'scopare', 'scopato',
            'chiavare', 'chiavata', 'chiavami', 'trombare', 'trombata', 'trombami',
            'fottere', 'fottimi', 'fottiti', 'fottuto',
            'masturbazione', 'masturbare', 'masturbami',
            'orgasmo', 'venire', 'vengo', 'godere', 'godo',
            'anale', 'anal', 'sodomia', 'sodomizzare',
            'orale', 'bocchino', 'leccata', 'leccami', 'succhiami', 'succhiamelo',
            'pompino', 'pompa', 'pompami',
            'gangbang', 'orgia', 'threesome', 'terzetto',
            'bondage', 'bdsm', 'fetish', 'feticismo',
            'squirt', 'squirting', 'creampie', 'facial',
            'nuda', 'nudo', 'nudi', 'nude', 'nudista',
            'spogliarsi', 'spogliami', 'spogliati',
            'eiaculazione', 'eiaculare', 'sborrata', 'sborrami', 'sborrare',
            'penetrazione', 'penetrami', 'penetrare',
            'dildo', 'vibratore', 'sex toy', 'sextoy',
            'cam girl', 'webcam', 'livecam', 'camshow',
            'escort', 'gigolo', 'prostituta', 'prostituzione',
            'hentai', 'milf', 'gilf', 'cougar', 'twink', 'daddy',
            'fisting', 'rimming', 'pegging', 'deepthroat',

            // ============================================================
            // TERMINI PER CERCARE INCONTRI / HOOKUP
            // ============================================================
            'scopamici', 'scopamica', 'scopamico',
            'trombamici', 'trombamica', 'trombamico',
            'incontri', 'incontro', 'incontrami', 'incontriamoci',
            'appuntamento', 'vediamoci', 'usciamo',
            'cerco sesso', 'voglio sesso', 'sesso occasionale',
            'amicizia plus', 'amicizia+', 'friends with benefits', 'fwb',
            'one night', 'one night stand', 'avventura',
            'no strings', 'senza impegno', 'casual',
            'scambisti', 'scambismo', 'scambio coppia', 'swingers',
            'cuckold', 'hotwife', 'bull',
            'cerco donna', 'cerco ragazza', 'cerco uomo', 'cerco maschio',
            'donna cerca', 'uomo cerca', 'ragazza cerca',
            'disponibile', 'libera', 'libero',
            'chat piccante', 'chat hot', 'chat erotica', 'sexchat',
            'foto hot', 'foto nude', 'foto nuda', 'foto piccanti',
            'mandami foto', 'invia foto', 'scambiamo foto',
            'videochat', 'video hot', 'video piccante',
            'numero whatsapp', 'scrivimi su', 'contattami',
            'telegram privato', 'snap privato', 'instagram privato',

            // ============================================================
            // TERMINI DENIGRATORI VERSO LE DONNE (MISOGINIA)
            // ============================================================
            'troia', 'puttana', 'zoccola', 'baldracca', 'sgualdrina',
            'bagascia', 'mignotta', 'battona', 'prostituta',
            'vacca', 'scrofa', 'maiala', 'cagna', 'porca',
            'put***a', 'tr**a', 'z*ccola', // Varianti censurate
            'donnaccia', 'poco di buono',
            'facile', 'da marciapiede', 'da strada',
            'slut', 'whore', 'hoe', 'bitch', 'skank', 'thot',
            'gold digger', 'cacciatrice di dote',
            'femmina', // Usato in modo denigratorio
            'femminuccia', 'donnicciola', 'donnetta',
            'oggetto', 'pezzo di carne', 'bambola',
            'sta zitta', 'zitta', 'taci donna',
            'cucina e lava', 'torna in cucina', 'fai la brava',
            'fare la squillo', 'fare la sgualdrina',

            // ============================================================
            // ATTRIBUTI SESSUALI MASCHILI (VOLGARI)
            // ============================================================
            'cazzo', 'cazzone', 'cazzetto', 'cazzino', 'cazzaccio',
            'pisello', 'pisellone', 'pisellino', 'piselletto',
            'minchia', 'minchione', 'minchiolino',
            'uccello', 'uccellone', 'uccellino',
            'pene', 'fallo', 'membro', 'verga',
            'pacco', 'paccone', 'paccotto',
            'coglioni', 'palle', 'palla', 'pallone', 'pallette',
            'testicoli', 'scroto', 'maroni', 'marroni',
            'dick', 'cock', 'penis', 'balls', 'nuts',
            'ce l\'ho duro', 'ce l\'ho grosso', 'ce l\'ho lungo',
            'dotato', 'superdotato', 'ben dotato', 'enorme',
            'erezione', 'eretto', 'in tiro', 'eccitato',
            'seghe', 'seghino', 'farsi una sega',
            'pippa', 'pippetta', 'farsi una pippa',

            // ============================================================
            // INSULTI GENERALI
            // ============================================================
            'idiota', 'scemo', 'deficiente', 'ritardato', 'mongoloide',
            'cerebroleso', 'handicappato', 'subnormale',
            'cretino', 'imbecille', 'stupido', 'pirla', 'babbeo',
            'tonto', 'citrullo', 'asino', 'somaro', 'bestia',

            // ============================================================
            // DISCRIMINAZIONI RAZZIALI/ETNICHE
            // ============================================================
            'negro', 'terrone', 'crucco', 'marocchino', 'zingaro',
            'frocio', 'finocchio', 'puttaniere', 'ricchione',
            'culattone', 'invertito', 'busone', 'checca',

            // ============================================================
            // VARIANTI E ABBREVIAZIONI
            // ============================================================
            'mrd', 'czz', 'str', 'fgt', 'ptn', 'trd',
            'c4zzo', 'f1ga', 'tr0ia', 'p0rn0',
            'cazz0', 'figa0', 'sborr4',

            // ============================================================
            // PAROLE IN ALTRE LINGUE
            // ============================================================
            'fuck', 'fucking', 'fucked', 'fucker',
            'shit', 'bullshit', 'shitty',
            'bitch', 'bitches', 'son of a bitch',
            'asshole', 'ass', 'arse',
            'damn', 'hell', 'bastard',
            'puta', 'mierda', 'joder', 'puto', 'cabrón', 'cojones',
            'salope', 'putain', 'merde', 'connard', 'enculer',
            'schlampe', 'hure', 'fotze', 'schwanz', 'arschloch',
        ];
    }

    /**
     * Carica pattern spam
     */
    private function loadSpamPatterns(): void
    {
        $this->spamPatterns = [
            // Link e promozioni
            '/(?:https?:\/\/|www\.)[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/i' => 'Link non autorizzati',
            '/(?:telegram|whatsapp|instagram|facebook|tiktok).*(?:@|\.me|\.com)/i' => 'Promozione social media non autorizzata',

            // Numeri di telefono
            '/(?:\+39|0039)?\s*[0-9]{3}[\s-]*[0-9]{3}[\s-]*[0-9]{4}/' => 'Numeri di telefono non autorizzati',

            // Email
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => 'Indirizzi email non autorizzati',

            // Pubblicità
            '/(?:comprare?|vendere?|gratis|offerta|sconto|euro?|€|\$|prezzo)/i' => 'Contenuto pubblicitario non autorizzato',

            // Messaggi ripetitivi
            '/(.{10,})\1{3,}/' => 'Testo ripetitivo',
        ];
    }
}
