<?php declare(strict_types = 1);
namespace MetaCat\Search;
use PDO;
use DateTime;
class Engine
{
    const STOP_WORDS = [
        'the', 'is', 'at', 'which', 'on', 'and', 'a', 'an', 'in', 'it', 'to', 'for', 'of', 'or', 'so',
        'از', 'به', 'در', 'که', 'این', 'را', 'و', 'با', 'آن', 'ها', 'هم', 'هر', 'اما', 'یا', 'رو'
    ];
    
    protected PDO $db;
    protected float $avgDocLength = 0;
    protected float $totalDocs = 0;
    protected array $pendingIndex = [];
    protected array $documents = [];
    protected int $count = 0;
    
    public function __construct(
        protected float $k1 = 1.5,
        protected float $b = 0.75,
        protected int $batchSize = 1000
    ) {
        $this->db = $this->getPdoConnection();
        $this->loadGlobalStats();
    }
    
    private function getPdoConnection(): PDO
    {
        // Example PDO connection setup - adjust as needed
        $dsn = 'mysql:host=127.0.0.1;dbname=se;charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        return new PDO($dsn, 'root', '', $options);
    }
    
    protected function normalizeText(string $text): string
    {
        $text = html_entity_decode($text);
        $text = str_replace(["\u{200C}", 'ي', 'آ', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '۰'], [' ', 'ی', 'ا', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0'], $text);
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{Arabic}\p{Latin}\p{N}\s]/u', '', $text);
        $text = preg_replace('/\p{M}/u', '', $text);
        return preg_replace('/\s+/', ' ', $text);
    }
    
    protected function tokenize(string $text): array
    {
        $text = $this->normalizeText($text);
        return preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    }
    
    protected function isStopWord(string $term): bool
    {
        return in_array($term, self::STOP_WORDS);
    }
    
    public function indexDocument(string $url, string $title, string $content, string $type, ?string $modifiedAt = null, ?string $thumbnail = null): void
    {
        $document = [$url, $title, $content, $type, $modifiedAt, $thumbnail];
        $this->documents = array_merge($this->documents, $document);
        $this->count++;

        if ($this->count >= $this->batchSize) {
            $placeholders = str_repeat('(?, ?, ?, ?, ?, ?),', $this->count);
            $placeholders = substr($placeholders, 0, -1);

            $this->db->beginTransaction();
            $this->db->prepare(
                "INSERT IGNORE INTO documents (url, title, content, type, modified_at, thumbnail) 
                VALUES $placeholders"
            )->execute($this->documents);
            $this->db->commit();

            $this->documents = [];
            $this->count = 0;
        }
        
        $combinedText = $title;
        $terms = $this->tokenize($combinedText);
        $terms = array_filter($terms, fn($term) => !$this->isStopWord($term));
        
        foreach ($terms as $term) {
            $this->pendingIndex[$url][$term] = ($this->pendingIndex[$url][$term] ?? 0) + 1;
        }
    }
    
    public function commitIndex(): void
    {
        if (empty($this->pendingIndex)) {
            return;
        }

        $this->db->beginTransaction();
        try {
            $batch = [];
            foreach ($this->pendingIndex as $docUrl => $terms) {
                foreach ($terms as $term => $freq) {
                    $batch[] = [$term, $docUrl, $freq];
                    if (count($batch) >= $this->batchSize) {
                        $this->executeBatch($batch);
                        $batch = [];
                    }
                }
            }
            
            if (!empty($batch)) {
                $this->executeBatch($batch);
            }
            
            $this->db->commit();
            $this->pendingIndex = [];
            $this->loadGlobalStats();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function executeBatch(array $batch): void
    {
        $placeholders = implode(',', array_fill(0, count($batch), '(?, ?, ?)'));
        $params = array_merge(...array_map(fn($entry) => [$entry[0], $entry[1], $entry[2]], $batch));
        
        $this->db->prepare(
            "INSERT INTO search_index (term, doc_url, frequency) VALUES $placeholders 
             ON DUPLICATE KEY UPDATE frequency = frequency + VALUES(frequency)"
        )->execute($params);
    }
    
    protected function loadGlobalStats(): void
    {
        $stmt = $this->db->query("SELECT COUNT(*) as total FROM documents");
        $this->totalDocs = (float)$stmt->fetchColumn();
        
        if ($this->totalDocs > 0) {
            $stmt = $this->db->query("SELECT SUM(frequency) as total_terms FROM search_index");
            $totalTerms = (float)$stmt->fetchColumn();
            $this->avgDocLength = $totalTerms / $this->totalDocs;
        }
    }
    
    public function search(string $query): array
    {
        $queryTerms = $this->tokenize($query);
        if (empty($queryTerms)) return [];
        $queryTerms = array_values(array_filter($queryTerms, fn($term) => !$this->isStopWord($term)));
        if (empty($queryTerms)) return [];
        
        $placeholders = implode(',', array_fill(0, count($queryTerms), '?'));
        $stmt = $this->db->prepare("SELECT doc_url, term, frequency FROM search_index WHERE term IN ($placeholders)");
        $stmt->execute($queryTerms);
        $indexData = $stmt->fetchAll();
        
        $queryTermsSet = array_flip($queryTerms);
        $docTermCounts = [];
        foreach ($indexData as $row) {
            $docTermCounts[$row['doc_url']][] = $row['term'];
        }
        
        $minTerms = ceil(0.51 * count($queryTerms));
        $filteredDocs = array_filter($docTermCounts, function($terms) use ($queryTermsSet, $minTerms) {
            $count = 0;
            foreach ($terms as $term) {
                if (isset($queryTermsSet[$term])) $count++;
            }
            return $count >= $minTerms;
        });
        
        if (empty($filteredDocs)) return [];
        
        $docIds = array_keys($filteredDocs);
        $idfMap = $this->calculateIDF($queryTerms);
        $docScores = $this->calculateBM25Scores($docIds, $queryTerms, $idfMap);
        $domainMetrics = $this->getDomainMetrics($docIds);
        $finalScores = $this->applyDomainMetrics($docScores, $domainMetrics);
        
        return $this->applyTimeBonus(
            $this->sortAndFetchDocuments($finalScores), 
            $finalScores
        );
    }
    
    protected function calculateIDF(array $terms): array
    {
        if (empty($terms)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($terms), '?'));
        $stmt = $this->db->prepare("SELECT term, COUNT(DISTINCT doc_url) as n 
                                   FROM search_index 
                                   WHERE term IN ($placeholders) 
                                   GROUP BY term");
        $stmt->execute($terms);
        $results = $stmt->fetchAll();
        
        $idfMap = [];
        foreach ($results as $row) {
            $idfMap[$row['term']] = log(($this->totalDocs - $row['n'] + 0.5) / ($row['n'] + 0.5) + 1);
        }
        return $idfMap;
    }
    
    protected function calculateBM25Scores(array $docUrls, array $queryTerms, array $idfMap): array
    {
        // 1. Fetch all term frequencies in ONE query (batched)
        $placeholdersDocs = implode(',', array_fill(0, count($docUrls), '?'));
        $placeholdersTerms = implode(',', array_fill(0, count($queryTerms), '?'));
        
        $stmt = $this->db->prepare(
            "SELECT doc_url, term, frequency 
            FROM search_index 
            WHERE doc_url IN ($placeholdersDocs)
            AND term IN ($placeholdersTerms)"
        );
        
        $stmt->execute(array_merge($docUrls, $queryTerms));
        $freqData = $stmt->fetchAll();
        
        // 2. Build term frequency matrix (in-memory)
        $termFrequencies = [];
        foreach ($freqData as $row) {
            $termFrequencies[$row['doc_url']][$row['term']] = $row['frequency'];
        }
        
        // 3. GET ALL DOCUMENT LENGTHS IN ONE BATCH (CRITICAL OPTIMIZATION)
        $docLengths = $this->getDocumentLength($docUrls);
        
        // 4. Calculate BM25 scores
        $scores = [];
        foreach ($docUrls as $docUrl) {
            $score = 0;
            $docLength = $docLengths[$docUrl] ?? 1; // Fallback to 1 if not found
            
            foreach ($queryTerms as $term) {
                $freq = $termFrequencies[$docUrl][$term] ?? 0;
                if ($freq > 0) {
                    $numerator = $freq * ($this->k1 + 1);
                    $denominator = $freq + $this->k1 * (1 - $this->b + $this->b * ($docLength / $this->avgDocLength));
                    $termScore = $idfMap[$term] * ($numerator / $denominator);
                    $score += $termScore;
                }
            }
            $scores[$docUrl] = $score;
        }
        
        return $scores;
    }
    
    protected function getDocumentLength(array $docUrls): array
    {
        if (empty($docUrls)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($docUrls), '?'));
        $stmt = $this->db->prepare(
            "SELECT doc_url, SUM(frequency) as len 
            FROM search_index 
            WHERE doc_url IN ($placeholders) 
            GROUP BY doc_url"
        );
        $stmt->execute($docUrls);
        $result = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // پر کردن مقادیر برای سند‌هایی که در جدول وجود ندارند
        $lengths = [];
        foreach ($docUrls as $url) {
            $lengths[$url] = (int)($result[$url] ?? 1);
        }
        return $lengths;
    }
    
    protected function getDomainMetrics(array $docUrls): array
    {
        $domains = array_values(array_unique(array_map(function (string $url) {
            $host = parse_url($url, PHP_URL_HOST);
            return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        }, $docUrls)));
        
        if (empty($domains)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($domains), '?'));
        $stmt = $this->db->prepare("SELECT domain, trust_score, security_score, engagement_score, load_speed, responsive 
                                   FROM websites 
                                   WHERE domain IN ($placeholders)");
        $stmt->execute($domains);
        $metrics = $stmt->fetchAll();
        return array_column($metrics, null, 'domain');
    }
    
    protected function applyDomainMetrics(array $scores, array $domainMetrics): array
    {
        foreach ($scores as $docUrl => $score) {
            $host = parse_url($docUrl, PHP_URL_HOST);
            if (isset($domainMetrics[$host])) {
                $metrics = $domainMetrics[$host];
                $avgScore = ($metrics['trust_score'] + $metrics['security_score'] + $metrics['engagement_score'] + $metrics['load_speed']) / 40;
                $responsiveFactor = $metrics['responsive'] ? 1 : 0.5;
                $scores[$docUrl] *= $avgScore * $responsiveFactor;
            }
        }
        return $scores;
    }
    
    protected function sortAndFetchDocuments(array $scores): array
    {
        arsort($scores);
        $sortedUrls = array_keys(array_slice($scores, 0, 300, true));
        if (empty($sortedUrls)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sortedUrls), '?'));
        $stmt = $this->db->prepare("SELECT url, title, content, modified_at, thumbnail FROM documents WHERE url IN ($placeholders)");
        $stmt->execute($sortedUrls);
        return $stmt->fetchAll();
    }
    
    protected function applyTimeBonus(array $docs, array $scores): array
    {
        $results = [];
        foreach ($docs as $doc) {
            $url = $doc['url'];
            $score = $scores[$url] ?? 0;
            $bonus = $doc['modified_at'] ? 1 / (1 + (int)date_diff(new DateTime(), new DateTime($doc['modified_at']))->days) : 0;
            $results[] = [
                'url' => $doc['url'],
                'title' => $doc['title'],
                'content' => $doc['content'],
                'score' => round($score + $bonus, 4),
                'modified_at' => $doc['modified_at'],
                'thumbnail' => $doc['thumbnail'] ?? null
            ];
        }
        return $results;
    }
    
    public function addWebsite(
        string $domain,
        int $trustScore,
        int $securityScore,
        int $engagementScore,
        int $loadSpeed,
        bool $responsive
    ): void {
        $this->validateWebsiteScores([
            'trust_score' => $trustScore,
            'security_score' => $securityScore,
            'engagement_score' => $engagementScore,
            'load_speed' => $loadSpeed
        ]);
        
        $this->db->prepare(
            "INSERT INTO websites (domain, trust_score, security_score, engagement_score, load_speed, responsive) 
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $domain,
            $trustScore,
            $securityScore,
            $engagementScore,
            $loadSpeed,
            $responsive
        ]);
    }
    
    public function updateWebsite(
        string $domain,
        int $trustScore,
        int $securityScore,
        int $engagementScore,
        int $loadSpeed,
        bool $responsive
    ): void {
        $this->validateWebsiteScores([
            'trust_score' => $trustScore,
            'security_score' => $securityScore,
            'engagement_score' => $engagementScore,
            'load_speed' => $loadSpeed
        ]);
        
        $this->db->prepare(
            "UPDATE websites SET 
                trust_score = ?, 
                security_score = ?, 
                engagement_score = ?, 
                load_speed = ?, 
                responsive = ? 
             WHERE domain = ?"
        )->execute([
            $trustScore,
            $securityScore,
            $engagementScore,
            $loadSpeed,
            $responsive,
            $domain
        ]);
    }
    
    protected function validateWebsiteScores(array $scores): void
    {
        foreach ($scores as $field => $value) {
            if ($value < 1 || $value > 10) {
                throw new \InvalidArgumentException(
                    "Invalid $field value: $value. Must be between 1 and 10."
                );
            }
        }
    }
}