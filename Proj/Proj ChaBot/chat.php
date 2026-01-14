<?php
declare(strict_types=1);

// Autoload (composer) si disponible
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Si composer/autoload n'existe pas, tenter de charger PHPMailer depuis ./src
if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
    $phpsrc = __DIR__ . '/src';
    if (file_exists($phpsrc . '/PHPMailer.php')) {
        require_once $phpsrc . '/Exception.php';
        require_once $phpsrc . '/PHPMailer.php';
        require_once $phpsrc . '/SMTP.php';
    }
}


// Configuration MySQL - mise à jour nécessaire
const DB_HOST = 'sql203.infinityfree.com';
const DB_NAME = 'if0_40900909_dbchat';
const DB_USER = 'if0_40900909';
const DB_PASS = 'hhEwtxjoE875cm';
const DB_CHARSET = 'utf8mb4';

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    // En production, logguer proprement ; ici on renvoie JSON d'erreur
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Connexion DB impossible: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------ Interfaces / Classes POO ------------------

interface StorageInterface
{
    public function hasRequiredTables(): bool;
    public function get(string $key): ?string;
    public function upsert(string $key, string $answer): bool;
    public function findSimilar(string $norm): ?string;
    public function saveMessage(string $role, string $content): void;
    public function logMail(string $recipient, ?string $subject, ?string $body, string $status, ?string $error = null): void;
    public function getAll(): array;
}

// Classe d'abstraction pour réutilisabilité et extension éventuelle
abstract class AbstractStorage implements StorageInterface
{
    protected PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Méthode utilitaire pour normaliser la clé de recherche si besoin
    protected function normalizeKey(string $k): string
    {
        return mb_strtolower(trim($k), 'UTF-8');
    }
}

// Implémentation MySQL conforme à StorageInterface
class MySQLStorage extends AbstractStorage
{
    public function hasRequiredTables(): bool
    {
        $required = ['question', 'messages', 'mail_logs'];
        $placeholders = implode(',', array_fill(0, count($required), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ($placeholders)"
        );
        $stmt->execute($required);
        $found = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return count(array_intersect($required, $found)) === count($required);
    }

    public function get(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT answer FROM question WHERE question = :q LIMIT 1');
        $stmt->execute([':q' => $key]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    public function upsert(string $key, string $answer): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO question (question, answer, created_at, updated_at)
                 VALUES (:q, :a, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE answer = VALUES(answer), updated_at = NOW()"
            );
            return $stmt->execute([':q' => $key, ':a' => $answer]);
        } catch (Throwable $e) {
            error_log('upsert error: ' . $e->getMessage());
            return false;
        }
    }

    public function findSimilar(string $norm): ?string
    {
        try {
            $stmt = $this->pdo->query('SELECT question, answer FROM question');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $k = (string)$row['question'];
                $v = (string)$row['answer'];
                if ($k === '') continue;
                if (strpos(mb_strtolower($k, 'UTF-8'), $norm) !== false || strpos($norm, mb_strtolower($k, 'UTF-8')) !== false) {
                    return $v;
                }
            }
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function saveMessage(string $role, string $content): void
    {
        try {
            $stmt = $this->pdo->prepare('INSERT INTO messages (role, content, created_at) VALUES (:r, :c, NOW())');
            $stmt->execute([':r' => $role, ':c' => $content]);
        } catch (Throwable $e) {
            // silent fail but log in error_log
            error_log('saveMessage error: ' . $e->getMessage());
        }
    }

    public function logMail(string $recipient, ?string $subject, ?string $body, string $status, ?string $error = null): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO mail_logs (recipient, subject, body, status, error, created_at) VALUES (:r, :s, :b, :st, :e, NOW())'
            );
            $stmt->execute([':r' => $recipient, ':s' => $subject, ':b' => $body, ':st' => $status, ':e' => $error]);
        } catch (Throwable $e) {
            error_log('logMail error: ' . $e->getMessage());
        }
    }

    public function getAll(): array
    {
        try {
            $rows = $this->pdo->query('SELECT question, answer FROM question')->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) $out[$r['question']] = $r['answer'];
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}

// ------------------ Normalizer (utilitaire) ------------------
class Normalizer
{
    public static function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        // enlever ponctuation sauf lettres/chiffres/espaces et ?
        $s = preg_replace('/[^\p{L}\p{N}\s\?]+/u', '', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }
}

// ------------------ Mailer (wrapper pour PHPMailer ou fallback) ------------------
class Mailer
{
    private array $cfg;
    private ?string $lastError = null;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;

        // Si PHPMailer n'est pas chargé, tenter de charger depuis ./src automatiquement
        if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            $phpsrc = __DIR__ . '/src';
            if (file_exists($phpsrc . '/PHPMailer.php')) {
                require_once $phpsrc . '/Exception.php';
                require_once $phpsrc . '/PHPMailer.php';
                require_once $phpsrc . '/SMTP.php';
            }
        }
    }

    public function isAvailable(): bool
    {
        return class_exists('\PHPMailer\PHPMailer\PHPMailer');
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Envoie un e-mail. Retourne true si ok, false sinon.
     * En cas d'échec, getLastError() contient le message d'erreur.
     */
    public function send(string $to, string $subject, string $htmlBody, string $altBody = ''): bool
    {
        $this->lastError = null;

        if ($this->isAvailable()) {
            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $this->cfg['host'];
                $mail->SMTPAuth = $this->cfg['smtp_auth'] ?? true;
                $mail->Username = $this->cfg['username'];
                $mail->Password = $this->cfg['password'];
                $mail->SMTPSecure = $this->cfg['encryption'] ?? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = (int)($this->cfg['port'] ?? 587);

                $mail->setFrom($this->cfg['from_address'], $this->cfg['from_name'] ?? 'Chatbot');
                $mail->addAddress($to);

                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $htmlBody;
                $mail->AltBody = $altBody ?: strip_tags($htmlBody);

                return (bool)$mail->send();
            } catch (\Throwable $e) {
                $this->lastError = 'PHPMailer: ' . $e->getMessage();
                error_log($this->lastError);
                return false;
            }
        }

        // Fallback : mail() natif (peu fiable en prod). On tente quand même.
        try {
            $headers = "From: " . ($this->cfg['from_address'] ?? 'no-reply@localhost') . "\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $ok = mail($to, $subject, $htmlBody, $headers);
            if (! $ok) $this->lastError = 'PHP mail() returned false';
            return (bool)$ok;
        } catch (\Throwable $e) {
            $this->lastError = 'mail() error: ' . $e->getMessage();
            error_log($this->lastError);
            return false;
        }
    }
}


// ------------------ BotCore (logique du chatbot) ------------------
class BotCore
{
    protected StorageInterface $storage;
    protected string $messageRaw;
    protected string $messageNorm;
    protected ?Mailer $mailer;

    public function __construct(StorageInterface $storage, string $message, ?Mailer $mailer = null)
    {
        $this->storage = $storage;
        $this->messageRaw = trim($message);
        $this->messageNorm = Normalizer::normalize($this->messageRaw);
        $this->mailer = $mailer;
    }

    public function handle(): array
    {
        if ($this->messageRaw === '') return ['error' => 'Aucun message reçu'];

        if (!$this->storage->hasRequiredTables()) {
            return ['error' => 'Tables manquantes (question/messages/mail_logs). Exécute le script SQL.'];
        }

        $this->storage->saveMessage('user', $this->messageRaw);

        // Mail command : mail : destinataire + objet + message
        $mail = $this->processMail();
        if ($mail !== null) {
            $this->storage->saveMessage('bot', $mail['reply'] ?? ($mail['error'] ?? ''));
            return $mail;
        }

        // Learn command : apprendre : question = réponse
        $learn = $this->processLearn();
        if ($learn !== null) {
            $this->storage->saveMessage('bot', $learn['reply'] ?? ($learn['error'] ?? ''));
            return $learn;
        }

        // Exact
        $exact = $this->storage->get($this->messageNorm);
        if ($exact !== null) {
            $this->storage->saveMessage('bot', $exact);
            return ['reply' => $exact];
        }

        // Similar
        $sim = $this->storage->findSimilar($this->messageNorm);
        if ($sim !== null) {
            $this->storage->saveMessage('bot', $sim);
            return ['reply' => $sim];
        }

        // Recherche Google
$search = $this->processSearch();
if ($search !== null) {
    $this->storage->saveMessage('bot', $search['reply'] ?? ($search['error'] ?? ''));
    return $search;
}


        // Greetings / How / Farewells
        if ($g = $this->handleGreetings()) { $this->storage->saveMessage('bot', $g); return ['reply' => $g]; }
        if ($h = $this->handleHowAreYou()) { $this->storage->saveMessage('bot', $h); return ['reply' => $h]; }
        if ($f = $this->handleFarewells()) { $this->storage->saveMessage('bot', $f); return ['reply' => $f]; }

        $default = "Waouhh ! Un nouveau mot. Aidez-moi à le connaitre avec apprendre : question = réponse";
        $this->storage->saveMessage('bot', $default);
        return ['reply' => $default];
    }

    protected function processMail(): ?array
    {
        $pattern = '/^\s*mail\s*:\s*(.+?)\s*\+\s*(.+?)\s*\+\s*(.+)\s*$/iu';
        if (preg_match($pattern, $this->messageRaw, $m)) {
            $to = trim($m[1]);
            $subject = trim($m[2]);
            $body = trim($m[3]);

            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return ['error' => "Adresse e-mail invalide : $to"];
            }

            if ($this->mailer === null) {
                $this->storage->logMail($to, $subject, $body, 'failed', 'mailer_not_configured');
                return ['error' => "Mailer non configuré. Impossible d'envoyer l'e-mail."];
            }

            $html = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $ok = $this->mailer->send($to, $subject, $html, $body);
            $status = $ok ? 'sent' : 'failed';
            $this->storage->logMail($to, $subject, $body, $status, $ok ? null : 'send_error');

            if ($ok) return ['reply' => "E-mail envoyé à $to (objet: $subject)."];
            return ['error' => "Échec envoi e-mail à $to."];
        }
        return null;
    }

    protected function processLearn(): ?array
    {
        $pattern = '/^\s*apprendre\s*:\s*(.+?)\s*=\s*(.+)\s*$/iu';
        if (preg_match($pattern, $this->messageRaw, $m)) {
            $questionRaw = trim($m[1]);
            $answerRaw = trim($m[2]);
            $key = Normalizer::normalize($questionRaw);
            if ($key === '' || $answerRaw === '') return ['error' => 'Question ou réponse vide.'];
            $ok = $this->storage->upsert($key, $answerRaw);
            if ($ok) return ['reply' => "J'ai appris : \"$questionRaw\" → \"$answerRaw\""]; 
            return ['error' => 'Impossible de sauvegarder (permission ou table manquante).'];
        }
        return null;
    }


protected function processSearch(): ?array
{
    $pattern = '/^\s*recherche\s*:\s*(.+)$/iu';
    if (preg_match($pattern, $this->messageRaw, $m)) {
        $query = trim($m[1]);
        if ($query === '') return ['error' => 'Recherche vide.'];

        $apiKey = 'AIzaSyASp9eUTxp1mMbO_3q0cvGC9YznWyjZRyU';
        $cx = 'a521c0322ef9b4433';

        $url = 'https://www.googleapis.com/customsearch/v1?' . http_build_query([
            'key' => $apiKey,
            'cx' => $cx,
            'q' => $query,
            'num' => 3,
            'lr' => 'lang_fr',
            'hl' => 'fr' 
        ]);

        $response = @file_get_contents($url);
        if ($response === false) return ['error' => 'Impossible de contacter l’API Google.'];

        $data = json_decode($response, true);
        if (!isset($data['items'])) return ['reply' => 'Aucun résultat trouvé.'];

$results = [];
foreach ($data['items'] as $item) {
    $results[] = [
        'title' => $item['title'] ?? 'Sans titre',
        'link'  => $item['link'] ?? ($item['formattedUrl'] ?? '#'),
        'snippet' => $item['snippet'] ?? ($item['htmlSnippet'] ?? '')
    ];
}

return ['reply' => "Résultats pour \"$query\" :", 'results' => $results];

    }
    return null;
}

    protected function handleGreetings(): ?string
    {
        $lower = mb_strtolower($this->messageRaw, 'UTF-8');
        if (preg_match('/\b(bonjour|salut|coucou|yo|bonsoir|hello|hi)\b/u', $lower)) {
            $dbg = $this->storage->get('bonjour');
            if ($dbg !== null) return $dbg;
            $greetings = ['Bonjour !', 'Salut !', 'Bonjour 🙂 Comment ça va ?'];
            return $greetings[array_rand($greetings)];
        }
        return null;
    }

    protected function handleHowAreYou(): ?string
    {
        $lower = mb_strtolower($this->messageRaw, 'UTF-8');
        if (preg_match('/\b(ca va|ça va|comment ça va|comment ca va|comment vas-tu|tu vas bien|comment allez-vous|vous allez bien)\b/iu', $lower)) {
            $cands = ['comment ça va','ça va','ca va','comment vas-tu','tu vas bien','comment allez-vous'];
            foreach ($cands as $c) { $v = $this->storage->get(Normalizer::normalize($c)); if ($v !== null) return $v; }
            $howReplies = ['Ça va très bien, merci ! Et toi ?', 'Je vais bien, merci 🙂 Et toi ?', 'Tout roule ici, merci — et toi ?'];
            return $howReplies[array_rand($howReplies)];
        }
        return null;
    }

    protected function handleFarewells(): ?string
    {
        $lower = mb_strtolower($this->messageRaw, 'UTF-8');
        if (preg_match('/\b(au revoir|aurevoir|à bientôt|a bientôt|à plus|a plus|a plus tard|bonne nuit|bye|ciao)\b/iu', $lower)) {
            $cands = ['au revoir','à bientôt','a bientôt','à plus','a plus','a plus tard','bonne nuit','bye','ciao'];
            foreach ($cands as $c) { $v = $this->storage->get(Normalizer::normalize($c)); if ($v !== null) return $v; }
            $farewells = ['Au revoir ! À bientôt.', 'À bientôt 👋', 'Bonne journée !', 'Bonne soirée et à bientôt !'];
            return $farewells[array_rand($farewells)];
        }
        return null;
    }
}

// ------------------ Bootstrap / Exécution ------------------
header('Content-Type: application/json; charset=utf-8');
$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload) || !isset($payload['message'])) {
    echo json_encode(['error' => 'Payload invalide'], JSON_UNESCAPED_UNICODE);
    exit;
}

$message = (string)$payload['message'];

// Instanciation du storage MySQL (POO)
$storage = new MySQLStorage($pdo);

// Configuration Mail (mettre tes vraies infos si tu veux activer l'envoi)
$mailCfg = [
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'username' => 'sarahtsahou@gmail.com',
    'password' => 'jvltqvlnoxjmhvka',
    'encryption' => 'tls',
    'from_address' => 'sarahtsahou@gmail.com',
    'from_name' => 'Chatbot'
];

// Décommenter la ligne suivante après avoir configuré PHPMailer et $mailCfg
// $mailer = new Mailer($mailCfg);

// Pour l'instant, on garde null si non configuré
$mailer = new Mailer($mailCfg);

$bot = new BotCore($storage, $message, $mailer);
$response = $bot->handle();

echo json_encode($response, JSON_UNESCAPED_UNICODE);

// Fin du fichier