<?php

/**
 * Enhanced Payment Processing System with Chaos Engineering - PHP Version
 * Improved with security, logging, persistence, and better architecture
 */

// Enhanced Configuration management
class Config {
    private static ?self $instance = null;
    private array $config;
    
    private function __construct() {
        // Load from environment with defaults
        $this->config = [
            'baseFailureRate' => (float)($_ENV['PAYMENT_BASE_FAILURE_RATE'] ?? 0.05),
            'maxRetries' => (int)($_ENV['PAYMENT_MAX_RETRIES'] ?? 2),
            'gatewayTimeout' => (float)($_ENV['PAYMENT_GATEWAY_TIMEOUT'] ?? 30.0),
            'fraudThreshold' => (float)($_ENV['PAYMENT_FRAUD_THRESHOLD'] ?? 0.7),
            'circuitBreakerFailures' => (int)($_ENV['CIRCUIT_BREAKER_FAILURES'] ?? 5),
            'circuitBreakerReset' => (float)($_ENV['CIRCUIT_BREAKER_RESET'] ?? 60.0),
            'maxAmount' => (float)($_ENV['PAYMENT_MAX_AMOUNT'] ?? 10000.0),
            'logLevel' => $_ENV['LOG_LEVEL'] ?? 'INFO',
        ];
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __get(string $name) {
        return $this->config[$name] ?? null;
    }
    
    public function getAll(): array {
        return $this->config;
    }
    
    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {}
}

// Enhanced error handling
class PaymentError extends Exception {
    private string $errorCode;
    private string $paymentId;
    private bool $retryable;
    private DateTime $timestamp;

    public function __construct(string $code, string $message, string $paymentId = "", bool $retryable = true) {
        $this->errorCode = $code;
        $this->paymentId = $paymentId;
        $this->retryable = $retryable;
        $this->timestamp = new DateTime();
        parent::__construct($message);
    }

    public function getErrorCode(): string {
        return $this->errorCode;
    }

    public function getPaymentId(): string {
        return $this->paymentId;
    }

    public function isRetryable(): bool {
        return $this->retryable;
    }

    public function getTimestamp(): DateTime {
        return $this->timestamp;
    }

    public function __toString(): string {
        return "[{$this->errorCode}] {$this->getMessage()} (payment: {$this->paymentId})";
    }

    public function toArray(): array {
        return [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'payment_id' => $this->paymentId,
            'retryable' => $this->retryable,
            'timestamp' => $this->timestamp->format(DateTime::ATOM)
        ];
    }
}

// Pre-defined error types
class PaymentErrors {
    public static function fraudDetected(): PaymentError {
        return new PaymentError('FRAUD', 'Transaction flagged as fraudulent', '', false);
    }
    
    public static function gatewayTimeout(): PaymentError {
        return new PaymentError('GATEWAY_TIMEOUT', 'Payment gateway timeout', '', true);
    }
    
    public static function invalidAmount(): PaymentError {
        return new PaymentError('INVALID_AMOUNT', 'Amount must be positive', '', false);
    }
    
    public static function invalidCurrency(): PaymentError {
        return new PaymentError('INVALID_CURRENCY', 'Invalid currency', '', false);
    }
    
    public static function circuitOpen(): PaymentError {
        return new PaymentError('CIRCUIT_OPEN', 'Circuit breaker is open', '', true);
    }
    
    public static function amountTooHigh(float $maxAmount): PaymentError {
        return new PaymentError('AMOUNT_TOO_HIGH', "Amount exceeds maximum allowed: {$maxAmount}", '', false);
    }
}

// Enhanced Logging System
class Logger {
    private string $logLevel;
    private string $logFile;
    private array $logLevels = ['DEBUG' => 1, 'INFO' => 2, 'WARNING' => 3, 'ERROR' => 4];
    
    public function __construct(string $logLevel = 'INFO', string $logFile = 'php://stderr') {
        $this->logLevel = $logLevel;
        $this->logFile = $logFile;
    }
    
    private function shouldLog(string $level): bool {
        return $this->logLevels[$level] >= ($this->logLevels[$this->logLevel] ?? 2);
    }
    
    public function log(string $level, string $message, array $context = []): void {
        if (!$this->shouldLog($level)) {
            return;
        }
        
        $timestamp = (new DateTime())->format(DateTime::ATOM);
        $contextStr = !empty($context) ? json_encode($context) : '';
        $logMessage = "[$timestamp] [$level] $message $contextStr" . PHP_EOL;
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    public function info(string $message, array $context = []): void {
        $this->log('INFO', $message, $context);
    }
    
    public function error(string $message, array $context = []): void {
        $this->log('ERROR', $message, $context);
    }
    
    public function debug(string $message, array $context = []): void {
        $this->log('DEBUG', $message, $context);
    }
    
    public function warning(string $message, array $context = []): void {
        $this->log('WARNING', $message, $context);
    }
}

// Business domain models
class Payment {
    public string $id;
    public float $amount;
    public string $currency;
    public string $merchantId;
    public string $customerId;
    public string $status = 'pending'; // pending, processing, completed, failed
    public DateTime $createdAt;
    public ?DateTime $processedAt = null;
    public string $errorReason = '';
    public int $retryCount = 0;
    public string $idempotencyKey = '';

    public function __construct(
        string $id,
        float $amount,
        string $currency,
        string $merchantId,
        string $customerId,
        string $idempotencyKey = ''
    ) {
        $this->id = $id;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->merchantId = $merchantId;
        $this->customerId = $customerId;
        $this->idempotencyKey = $idempotencyKey;
        $this->createdAt = new DateTime();
    }

    public function sanitize(): void {
        // Sanitize inputs
        $this->id = htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8');
        $this->merchantId = htmlspecialchars($this->merchantId, ENT_QUOTES, 'UTF-8');
        $this->customerId = htmlspecialchars($this->customerId, ENT_QUOTES, 'UTF-8');
        $this->currency = strtoupper(trim($this->currency));
        $this->amount = round($this->amount, 2); // Ensure proper precision
    }

    public function validate(): void {
        $this->sanitize();
        
        if ($this->amount <= 0) {
            throw PaymentErrors::invalidAmount();
        }
        
        // Validate amount precision
        if (round($this->amount, 2) != $this->amount) {
            throw new PaymentError('INVALID_AMOUNT_PRECISION', 'Amount must have at most 2 decimal places', '', false);
        }
        
        $validCurrencies = ['USD', 'EUR', 'GBP', 'CAD'];
        if (!in_array($this->currency, $validCurrencies)) {
            throw PaymentErrors::invalidCurrency();
        }
        
        if (empty($this->merchantId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $this->merchantId)) {
            throw new PaymentError('INVALID_MERCHANT', 'Merchant ID is required and must be alphanumeric', '', false);
        }
        
        if (empty($this->customerId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $this->customerId)) {
            throw new PaymentError('INVALID_CUSTOMER', 'Customer ID is required and must be alphanumeric', '', false);
        }
        
        // Validate ID length and format
        if (!preg_match('/^pay_\d+_\d+$/', $this->id)) {
            throw new PaymentError('INVALID_PAYMENT_ID', 'Invalid payment ID format', '', false);
        }
    }

    public function toArray(): array {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'merchant_id' => $this->merchantId,
            'customer_id' => $this->customerId,
            'status' => $this->status,
            'created_at' => $this->createdAt->format(DateTime::ATOM),
            'processed_at' => $this->processedAt ? $this->processedAt->format(DateTime::ATOM) : null,
            'error_reason' => $this->errorReason,
            'retry_count' => $this->retryCount,
            'idempotency_key' => $this->idempotencyKey
        ];
    }
}

class PaymentGateway {
    public string $name;
    public float $successRate;
    public float $latency; // in seconds
    public bool $isActive;

    public function __construct(string $name, float $successRate, float $latency, bool $isActive = true) {
        $this->name = $name;
        $this->successRate = $successRate;
        $this->latency = $latency;
        $this->isActive = $isActive;
    }
}

class FraudDetectionResult {
    public bool $isFraudulent;
    public float $riskScore;
    public array $reasons;

    public function __construct(bool $isFraudulent, float $riskScore, array $reasons = []) {
        $this->isFraudulent = $isFraudulent;
        $this->riskScore = $riskScore;
        $this->reasons = $reasons;
    }

    public function toArray(): array {
        return [
            'is_fraudulent' => $this->isFraudulent,
            'risk_score' => $this->riskScore,
            'reasons' => $this->reasons
        ];
    }
}

// Circuit Breaker pattern
class CircuitBreakerState {
    const CLOSED = 'closed';
    const OPEN = 'open';
    const HALF_OPEN = 'half_open';
}

class CircuitBreaker {
    private int $maxFailures;
    private float $resetTimeout;
    private int $failures = 0;
    private ?float $lastFailure = null;
    private $lock;
    private int $totalRequests = 0;
    private int $failedRequests = 0;
    private float $lastSuccessTime = 0;

    public function __construct(int $maxFailures, float $resetTimeout) {
        $this->maxFailures = $maxFailures;
        $this->resetTimeout = $resetTimeout;
        $this->lock = fopen('php://temp', 'r+'); // Simple lock mechanism
    }

    public function __destruct() {
        if (is_resource($this->lock)) {
            fclose($this->lock);
        }
    }

    public function allow(): bool {
        flock($this->lock, LOCK_EX);
        try {
            if ($this->failures >= $this->maxFailures) {
                if ($this->lastFailure && (microtime(true) - $this->lastFailure) > $this->resetTimeout) {
                    // Auto-reset after timeout
                    $this->failures = 0;
                    $this->totalRequests++;
                    return true;
                }
                return false;
            }
            $this->totalRequests++;
            return true;
        } finally {
            flock($this->lock, LOCK_UN);
        }
    }

    public function recordSuccess(): void {
        flock($this->lock, LOCK_EX);
        try {
            $this->failures = 0;
            $this->lastSuccessTime = microtime(true);
        } finally {
            flock($this->lock, LOCK_UN);
        }
    }

    public function recordFailure(): void {
        flock($this->lock, LOCK_EX);
        try {
            $this->failures++;
            $this->failedRequests++;
            $this->lastFailure = microtime(true);
        } finally {
            flock($this->lock, LOCK_UN);
        }
    }

    public function state(): string {
        flock($this->lock, LOCK_EX);
        try {
            if ($this->failures >= $this->maxFailures) {
                if ($this->lastFailure && (microtime(true) - $this->lastFailure) > $this->resetTimeout) {
                    return CircuitBreakerState::HALF_OPEN;
                }
                return CircuitBreakerState::OPEN;
            }
            return CircuitBreakerState::CLOSED;
        } finally {
            flock($this->lock, LOCK_UN);
        }
    }

    public function getMetrics(): array {
        flock($this->lock, LOCK_EX);
        try {
            return [
                'state' => $this->state(),
                'failures' => $this->failures,
                'max_failures' => $this->maxFailures,
                'failure_rate' => $this->totalRequests > 0 ? $this->failedRequests / $this->totalRequests : 0,
                'total_requests' => $this->totalRequests,
                'failed_requests' => $this->failedRequests,
                'last_failure' => $this->lastFailure,
                'reset_timeout' => $this->resetTimeout,
            ];
        } finally {
            flock($this->lock, LOCK_UN);
        }
    }
}

class PaymentMetrics {
    public int $totalProcessed = 0;
    public int $successful = 0;
    public int $failed = 0;
    public int $fraudDetected = 0;
    public float $totalAmount = 0.0;
    public float $averageProcessingTime = 0.0;
    public float $successRate = 0.0;
    public int $circuitBreakerTrips = 0;

    public function toArray(): array {
        return [
            'total_processed' => $this->totalProcessed,
            'successful' => $this->successful,
            'failed' => $this->failed,
            'fraud_detected' => $this->fraudDetected,
            'total_amount' => $this->totalAmount,
            'average_processing_time' => $this->averageProcessingTime,
            'success_rate' => $this->successRate,
            'circuit_breaker_trips' => $this->circuitBreakerTrips
        ];
    }
}

// Database Abstraction for Persistence
interface PaymentRepositoryInterface {
    public function save(Payment $payment): bool;
    public function findById(string $id): ?Payment;
    public function findByIdempotencyKey(string $key): ?Payment;
    public function findRecentByCustomer(string $customerId, int $limit = 10): array;
}

class FilePaymentRepository implements PaymentRepositoryInterface {
    private string $storagePath;
    private $lock;
    
    public function __construct(string $storagePath) {
        $this->storagePath = rtrim($storagePath, '/') . '/';
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
        $this->lock = fopen('php://temp', 'r+');
    }
    
    public function save(Payment $payment): bool {
        $filename = $this->storagePath . $payment->id . '.json';
        flock($this->lock, LOCK_EX);
        try {
            return file_put_contents($filename, json_encode($payment->toArray()), LOCK_EX) !== false;
        } finally {
            flock($this->lock, LOCK_UN);
        }
    }
    
    public function findById(string $id): ?Payment {
        $filename = $this->storagePath . $id . '.json';
        if (!file_exists($filename)) {
            return null;
        }
        
        flock($this->lock, LOCK_SH);
        try {
            $data = json_decode(file_get_contents($filename), true);
            if (!$data) return null;
            
            return $this->hydratePayment($data);
        } finally {
            flock($this->lock, LOCK_UN);
        }
    }
    
    public function findByIdempotencyKey(string $key): ?Payment {
        if (empty($key)) {
            return null;
        }
        
        // This is a simplified implementation - in production, you'd index by idempotency key
        $files = glob($this->storagePath . '*.json');
        foreach ($files as $file) {
            flock($this->lock, LOCK_SH);
            try {
                $data = json_decode(file_get_contents($file), true);
                if ($data && isset($data['idempotency_key']) && $data['idempotency_key'] === $key) {
                    return $this->hydratePayment($data);
                }
            } finally {
                flock($this->lock, LOCK_UN);
            }
        }
        
        return null;
    }
    
    public function findRecentByCustomer(string $customerId, int $limit = 10): array {
        $payments = [];
        $files = glob($this->storagePath . '*.json');
        
        foreach ($files as $file) {
            if (count($payments) >= $limit) break;
            
            flock($this->lock, LOCK_SH);
            try {
                $data = json_decode(file_get_contents($file), true);
                if ($data && isset($data['customer_id']) && $data['customer_id'] === $customerId) {
                    $payments[] = $this->hydratePayment($data);
                }
            } finally {
                flock($this->lock, LOCK_UN);
            }
        }
        
        // Sort by creation date, most recent first
        usort($payments, function($a, $b) {
            return $b->createdAt <=> $a->createdAt;
        });
        
        return array_slice($payments, 0, $limit);
    }
    
    private function hydratePayment(array $data): Payment {
        $payment = new Payment(
            $data['id'],
            $data['amount'],
            $data['currency'],
            $data['merchant_id'],
            $data['customer_id'],
            $data['idempotency_key']
        );
        
        $payment->status = $data['status'];
        $payment->createdAt = new DateTime($data['created_at']);
        $payment->processedAt = $data['processed_at'] ? new DateTime($data['processed_at']) : null;
        $payment->errorReason = $data['error_reason'];
        $payment->retryCount = $data['retry_count'];
        
        return $payment;
    }
}

// Core business service
class PaymentProcessor {
    private Config $config;
    private array $gateways;
    private FraudDetectionService $fraudService;
    private ChaosInjector $chaosInjector;
    private array $circuitBreakers = [];
    private PaymentRepositoryInterface $repository;
    private PaymentMetrics $metrics;
    private Logger $logger;
    private $lock;

    public function __construct(
        ?Config $config = null,
        ?PaymentRepositoryInterface $repository = null,
        ?Logger $logger = null
    ) {
        $this->config = $config ?? Config::getInstance();
        $this->repository = $repository ?? new FilePaymentRepository('./data/payments');
        $this->logger = $logger ?? new Logger();
        
        $this->gateways = [
            new PaymentGateway('Stripe', 0.98, 0.2),
            new PaymentGateway('PayPal', 0.96, 0.3),
            new PaymentGateway('Square', 0.97, 0.25),
            new PaymentGateway('Adyen', 0.99, 0.15),
        ];
        
        $this->fraudService = new FraudDetectionService($this->config);
        $this->chaosInjector = new ChaosInjector($this->config);
        $this->metrics = new PaymentMetrics();
        $this->lock = fopen('php://temp', 'r+');
        
        foreach ($this->gateways as $gateway) {
            $this->circuitBreakers[$gateway->name] = new CircuitBreaker(
                $this->config->circuitBreakerFailures,
                $this->config->circuitBreakerReset
            );
        }
        
        $this->logger->info('PaymentProcessor initialized', [
            'gateways' => count($this->gateways),
            'config' => $this->config->getAll()
        ]);
    }

    public function __destruct() {
        if (is_resource($this->lock)) {
            fclose($this->lock);
        }
    }

    public function processPayment(float $amount, string $currency, string $merchantId, string $customerId): Payment {
        return $this->processPaymentWithContext($amount, $currency, $merchantId, $customerId, '');
    }

    public function processPaymentWithContext(
        float $amount,
        string $currency,
        string $merchantId,
        string $customerId,
        string $idempotencyKey
    ): Payment {
        // Check idempotency first
        if (!empty($idempotencyKey)) {
            $existingPayment = $this->repository->findByIdempotencyKey($idempotencyKey);
            if ($existingPayment) {
                $this->logger->info('Returning existing payment for idempotency key', [
                    'idempotency_key' => $idempotencyKey,
                    'payment_id' => $existingPayment->id
                ]);
                return $existingPayment;
            }
        }

        $startTime = microtime(true);

        $payment = new Payment(
            $this->generatePaymentId(),
            $amount,
            $currency,
            $merchantId,
            $customerId,
            $idempotencyKey
        );

        try {
            $payment->validate();
            
            // Check maximum amount
            if ($this->config->maxAmount > 0 && $payment->amount > $this->config->maxAmount) {
                throw PaymentErrors::amountTooHigh($this->config->maxAmount);
            }
            
            $this->repository->save($payment);
            
            $this->logger->info('Payment validation successful', [
                'payment_id' => $payment->id,
                'amount' => $amount,
                'customer_id' => $customerId
            ]);
        } catch (PaymentError $e) {
            $this->logger->error('Payment validation failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'error_code' => $e->getErrorCode()
            ]);
            return $this->handlePaymentFailure($payment, $e, $startTime);
        }

        $this->logger->info("Processing payment {$payment->id}", [
            'amount' => $amount,
            'customer_id' => $customerId,
            'merchant_id' => $merchantId
        ]);

        try {
            // Step 1: Fraud detection
            $fraudResult = $this->fraudService->checkPayment($payment);
            if ($fraudResult->isFraudulent) {
                flock($this->lock, LOCK_EX);
                $this->metrics->fraudDetected++;
                flock($this->lock, LOCK_UN);
                
                $fraudError = new PaymentError(
                    'FRAUD_DETECTED',
                    'Fraud detected: ' . implode(', ', $fraudResult->reasons),
                    $payment->id,
                    false
                );
                
                $this->logger->warning('Fraud detected in payment', [
                    'payment_id' => $payment->id,
                    'risk_score' => $fraudResult->riskScore,
                    'reasons' => $fraudResult->reasons
                ]);
                
                return $this->handlePaymentFailure($payment, $fraudError, $startTime);
            }

            // Step 2: Inject chaos
            $this->chaosInjector->injectPaymentChaos($payment);

            // Step 3: Process with selected gateway
            $gateway = $this->selectPaymentGateway();
            
            // Check circuit breaker
            $circuitBreaker = $this->circuitBreakers[$gateway->name];
            if (!$circuitBreaker->allow()) {
                $circuitError = new PaymentError(
                    'CIRCUIT_BREAKER_OPEN',
                    "Gateway {$gateway->name} circuit breaker is open",
                    $payment->id,
                    true
                );
                
                $this->logger->warning('Circuit breaker blocked payment', [
                    'payment_id' => $payment->id,
                    'gateway' => $gateway->name,
                    'circuit_state' => $circuitBreaker->state()
                ]);
                
                return $this->handlePaymentFailure($payment, $circuitError, $startTime);
            }

            $payment->status = 'processing';
            $this->repository->save($payment);

            // Process with gateway
            list($success, $processError) = $this->processWithGateway($payment, $gateway);
            
            if ($processError) {
                $circuitBreaker->recordFailure();
                flock($this->lock, LOCK_EX);
                $this->metrics->circuitBreakerTrips++;
                flock($this->lock, LOCK_UN);
                
                $this->logger->error('Gateway processing failed', [
                    'payment_id' => $payment->id,
                    'gateway' => $gateway->name,
                    'error' => $processError->getMessage()
                ]);
                
                return $this->handlePaymentFailure($payment, $processError, $startTime);
            }

            if (!$success) {
                // Retry logic
                if ($payment->retryCount < $this->config->maxRetries) {
                    $payment->retryCount++;
                    $this->logger->info("Retrying payment {$payment->id}", [
                        'attempt' => $payment->retryCount,
                        'max_retries' => $this->config->maxRetries
                    ]);
                    
                    list($success, ) = $this->processWithGateway($payment, $gateway);
                }
            }

            if ($success) {
                $circuitBreaker->recordSuccess();
                return $this->handlePaymentSuccess($payment, $gateway->name, $startTime);
            } else {
                $circuitBreaker->recordFailure();
                flock($this->lock, LOCK_EX);
                $this->metrics->circuitBreakerTrips++;
                flock($this->lock, LOCK_UN);
                
                $gatewayError = new PaymentError(
                    'GATEWAY_FAILURE',
                    'All payment attempts failed',
                    $payment->id,
                    true
                );
                
                $this->logger->error('All payment attempts failed', [
                    'payment_id' => $payment->id,
                    'retry_count' => $payment->retryCount
                ]);
                
                return $this->handlePaymentFailure($payment, $gatewayError, $startTime);
            }

        } catch (Exception $e) {
            if (!$e instanceof PaymentError) {
                $e = new PaymentError('UNEXPECTED_ERROR', $e->getMessage(), $payment->id, true);
            }
            
            $this->logger->error('Unexpected error processing payment', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->handlePaymentFailure($payment, $e, $startTime);
        }
    }

    private function processWithGateway(Payment $payment, PaymentGateway $gateway): array {
        try {
            $this->logger->debug('Processing with gateway', [
                'payment_id' => $payment->id,
                'gateway' => $gateway->name,
                'latency' => $gateway->latency
            ]);

            // Simulate gateway latency
            $processingTime = $gateway->latency + (mt_rand(0, 100) / 1000); // Add random jitter
            
            // Use timeout simulation
            $start = microtime(true);
            while ((microtime(true) - $start) < $processingTime) {
                if ((microtime(true) - $start) > $this->config->gatewayTimeout) {
                    return [false, PaymentErrors::gatewayTimeout()];
                }
                usleep(1000); // Sleep 1ms to prevent busy waiting
            }

            // Determine success based on gateway success rate and chaos
            $successThreshold = $gateway->successRate * $this->chaosInjector->getSuccessRateModifier();
            $success = mt_rand() / mt_getrandmax() <= $successThreshold;
            
            $this->logger->debug('Gateway processing completed', [
                'payment_id' => $payment->id,
                'gateway' => $gateway->name,
                'success' => $success,
                'success_threshold' => $successThreshold
            ]);
            
            return [$success, null];

        } catch (Exception $e) {
            $this->logger->error('Exception in gateway processing', [
                'payment_id' => $payment->id,
                'gateway' => $gateway->name,
                'error' => $e->getMessage()
            ]);
            
            return [false, new PaymentError('GATEWAY_ERROR', $e->getMessage(), $payment->id, true)];
        }
    }

    private function selectPaymentGateway(): PaymentGateway {
        flock($this->lock, LOCK_EX);
        try {
            $activeGateways = [];
            foreach ($this->gateways as $gateway) {
                if ($gateway->isActive) {
                    $state = $this->circuitBreakers[$gateway->name]->state();
                    if ($state !== CircuitBreakerState::OPEN) {
                        $activeGateways[] = $gateway;
                    }
                }
            }

            if (empty($activeGateways)) {
                // Fallback to first gateway in emergency
                $this->logger->warning('No active gateways available, using fallback');
                return $this->gateways[0];
            }

            $selected = $activeGateways[array_rand($activeGateways)];
            $this->logger->debug('Selected payment gateway', [
                'gateway' => $selected->name,
                'available_gateways' => count($activeGateways)
            ]);
            
            return $selected;
        } finally {
            flock($this->lock, LOCK_UN);
        }
    }

    private function handlePaymentSuccess(Payment $payment, string $gateway, float $startTime): Payment {
        $processingTime = microtime(true) - $startTime;

        flock($this->lock, LOCK_EX);
        try {
            $payment->status = 'completed';
            $payment->processedAt = new DateTime();

            $this->metrics->successful++;
            $this->metrics->totalProcessed++;
            $this->metrics->totalAmount += $payment->amount;

            // Update average processing time
            if ($this->metrics->successful === 1) {
                $this->metrics->averageProcessingTime = $processingTime;
            } else {
                $this->metrics->averageProcessingTime = (
                    ($this->metrics->averageProcessingTime * ($this->metrics->successful - 1) + $processingTime) 
                    / $this->metrics->successful
                );
            }

            if ($this->metrics->totalProcessed > 0) {
                $this->metrics->successRate = $this->metrics->successful / $this->metrics->totalProcessed;
            } else {
                $this->metrics->successRate = 0.0;
            }
        } finally {
            flock($this->lock, LOCK_UN);
        }

        $this->repository->save($payment);
        
        $this->logger->info("Payment {$payment->id} completed successfully", [
            'gateway' => $gateway,
            'processing_time' => $processingTime,
            'amount' => $payment->amount
        ]);
        
        return $payment;
    }

    private function handlePaymentFailure(Payment $payment, PaymentError $error, float $startTime): Payment {
        $processingTime = microtime(true) - $startTime;
        
        flock($this->lock, LOCK_EX);
        try {
            $payment->status = 'failed';
            $payment->errorReason = (string)$error;
            $payment->processedAt = new DateTime();

            $this->metrics->failed++;
            $this->metrics->totalProcessed++;
            
            if ($this->metrics->totalProcessed > 0) {
                $this->metrics->successRate = $this->metrics->successful / $this->metrics->totalProcessed;
            } else {
                $this->metrics->successRate = 0.0;
            }
        } finally {
            flock($this->lock, LOCK_UN);
        }

        $this->repository->save($payment);
        
        $this->logger->error("Payment {$payment->id} failed", [
            'error' => $error->getMessage(),
            'error_code' => $error->getErrorCode(),
            'processing_time' => $processingTime,
            'retryable' => $error->isRetryable()
        ]);
        
        return $payment;
    }

    public function generateBusinessReport(): array {
        $revenueByMerchant = [];
        $circuitStates = [];
        $gatewayMetrics = [];

        foreach ($this->repository->findRecentByCustomer('', 1000) as $payment) {
            if ($payment->status === 'completed') {
                $revenueByMerchant[$payment->merchantId] = 
                    ($revenueByMerchant[$payment->merchantId] ?? 0.0) + $payment->amount;
            }
        }

        // Get circuit breaker states and metrics
        foreach ($this->circuitBreakers as $gatewayName => $circuitBreaker) {
            $circuitStates[$gatewayName] = $circuitBreaker->state();
            $gatewayMetrics[$gatewayName] = $circuitBreaker->getMetrics();
        }

        return [
            'metrics' => $this->metrics->toArray(),
            'revenue_by_merchant' => $revenueByMerchant,
            'gateway_metrics' => $gatewayMetrics,
            'circuit_breaker_states' => $circuitStates,
            'system_health' => $this->getSystemHealth(),
            'timestamp' => (new DateTime())->format(DateTime::ATOM)
        ];
    }

    public function saveReportToFile(string $filename): void {
        $report = $this->generateBusinessReport();
        
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        if (file_put_contents($filename, json_encode($report, JSON_PRETTY_PRINT)) === false) {
            throw new RuntimeException("Failed to save report to $filename");
        }
        
        $this->logger->info("Report saved to $filename");
    }

    public function getGatewayMetrics(): array {
        $metrics = [];
        foreach ($this->circuitBreakers as $gatewayName => $circuitBreaker) {
            $metrics[$gatewayName] = $circuitBreaker->getMetrics();
        }
        return $metrics;
    }
    
    public function resetCircuitBreaker(string $gatewayName): bool {
        if (!isset($this->circuitBreakers[$gatewayName])) {
            return false;
        }
        
        // In a real implementation, you'd add reset logic to CircuitBreaker
        $this->logger->info('Circuit breaker reset requested', ['gateway' => $gatewayName]);
        return true;
    }
    
    public function getSystemHealth(): array {
        $health = [
            'overall' => 'healthy',
            'timestamp' => (new DateTime())->format(DateTime::ATOM),
            'gateways' => [],
            'metrics' => $this->metrics->toArray()
        ];
        
        $unhealthyGateways = 0;
        foreach ($this->getGatewayMetrics() as $gateway => $metrics) {
            $health['gateways'][$gateway] = $metrics;
            if ($metrics['state'] === CircuitBreakerState::OPEN) {
                $unhealthyGateways++;
            }
        }
        
        if ($unhealthyGateways > count($this->gateways) / 2) {
            $health['overall'] = 'degraded';
        } elseif ($unhealthyGateways === count($this->gateways)) {
            $health['overall'] = 'unhealthy';
        }
        
        return $health;
    }

    private function generatePaymentId(): string {
        return 'pay_' . (int)(microtime(true) * 1000) . '_' . mt_rand(1000, 9999);
    }
}

// Fraud Detection Service
class FraudDetectionService {
    private Config $config;
    private array $riskPatterns;
    private Logger $logger;

    public function __construct(Config $config, ?Logger $logger = null) {
        $this->config = $config;
        $this->logger = $logger ?? new Logger();
        $this->riskPatterns = [
            'high_amount_velocity',
            'unusual_geolocation',
            'suspicious_device',
            'risky_merchant_category',
            'card_testing_pattern',
        ];
    }

    public function checkPayment(Payment $payment): FraudDetectionResult {
        $this->logger->debug('Running fraud detection', ['payment_id' => $payment->id]);
        
        // Simulate fraud detection processing
        usleep(50000); // 50ms

        $riskScore = mt_rand() / mt_getrandmax();
        $reasons = [];

        // High amount transactions have higher risk
        if ($payment->amount > 1000) {
            $riskScore += 0.3;
            $reasons[] = 'high_amount';
        }

        // Random risk patterns
        if (mt_rand() / mt_getrandmax() < 0.1) {
            $pattern = $this->riskPatterns[array_rand($this->riskPatterns)];
            $reasons[] = $pattern;
            $riskScore += 0.4;
        }

        // Ensure risk score is between 0 and 1
        $riskScore = min(max($riskScore, 0.0), 1.0);
        $isFraudulent = $riskScore > $this->config->fraudThreshold;

        $this->logger->debug('Fraud detection completed', [
            'payment_id' => $payment->id,
            'risk_score' => $riskScore,
            'is_fraudulent' => $isFraudulent,
            'reasons' => $reasons
        ]);

        return new FraudDetectionResult($isFraudulent, $riskScore, $reasons);
    }
}

// Chaos Injector
class ChaosInjector {
    private Config $config;
    private float $failureRate;
    private float $latencyRange = 2.0; // 2 seconds max latency
    private array $gatewayOutages = [];
    private $lock;
    private Logger $logger;

    public function __construct(Config $config, ?Logger $logger = null) {
        $this->config = $config;
        $this->failureRate = $config->baseFailureRate;
        $this->logger = $logger ?? new Logger();
        $this->lock = fopen('php://temp', 'r+');
    }

    public function __destruct() {
        if (is_resource($this->lock)) {
            fclose($this->lock);
        }
    }

    public function injectPaymentChaos(Payment $payment): void {
        // Random gateway outages
        if (mt_rand() / mt_getrandmax() < 0.02) { // 2% chance of gateway outage
            $gateway = (mt_rand() / mt_getrandmax() < 0.5) ? 'Stripe' : 'PayPal';
            flock($this->lock, LOCK_EX);
            $this->gatewayOutages[$gateway] = true;
            flock($this->lock, LOCK_UN);
            $this->logger->warning('Simulating gateway outage', ['gateway' => $gateway]);
        }

        // Random latency spikes
        if (mt_rand() / mt_getrandmax() < 0.03) { // 3% chance of high latency
            $latency = mt_rand() / mt_getrandmax() * $this->latencyRange;
            usleep((int)($latency * 1000000));
            $this->logger->warning('Injected latency', ['latency' => $latency]);
        }

        // Simulate network timeouts (handled in gateway processing)
    }

    public function getSuccessRateModifier(): float {
        $modifier = 1.0;
        flock($this->lock, LOCK_EX);
        $outages = count($this->gatewayOutages);
        flock($this->lock, LOCK_UN);
        if ($outages > 0) {
            $modifier -= 0.1 * $outages;
        }
        return max($modifier, 0.0);
    }
}

// Demo execution with enhanced error handling
function main() {
    try {
        // Set up error handling
        set_error_handler(function($errno, $errstr, $errfile, $errline) {
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
        
        echo "Enhanced Payment Processing System with Chaos Engineering - PHP Version\n";
        echo str_repeat("=", 80) . "\n";

        // Load environment configuration
        $config = Config::getInstance();
        $logger = new Logger($config->logLevel);
        $repository = new FilePaymentRepository('./data/payments');
        
        $processor = new PaymentProcessor($config, $repository, $logger);

        // Simulate business transactions
        $merchants = ['amazon', 'netflix', 'spotify', 'uber', 'starbucks'];
        $customers = ['cust_001', 'cust_002', 'cust_003', 'cust_004', 'cust_005'];

        echo "\nProcessing payments...\n";

        // Process multiple payments
        $successful = 0;
        $failed = 0;

        for ($i = 0; $i < 25; $i++) {
            $amount = 10 + mt_rand(0, 500);
            $merchant = $merchants[array_rand($merchants)];
            $customer = $customers[array_rand($customers)];
            $idempotencyKey = "txn_{$i}_" . time();

            try {
                $payment = $processor->processPaymentWithContext($amount, 'USD', $merchant, $customer, $idempotencyKey);

                if ($payment->status === 'completed') {
                    echo "Transaction $i successful: {$payment->id} \${$payment->amount}\n";
                    $successful++;
                } else {
                    echo "Transaction $i failed: {$payment->errorReason}\n";
                    $failed++;
                }
            } catch (Exception $e) {
                echo "Transaction $i failed with exception: {$e->getMessage()}\n";
                $failed++;
            }

            // Small delay between transactions
            usleep(100000); // 100ms
        }

        echo "\nCompleted $successful successful transactions, $failed failed\n";

        // Generate business report
        echo "\nEnhanced Business Report:\n";
        echo str_repeat("=", 50) . "\n";

        $report = $processor->generateBusinessReport();
        $metrics = $report['metrics'];
        $circuitStates = $report['circuit_breaker_states'];
        $systemHealth = $report['system_health'];

        echo "Total Processed: {$metrics['total_processed']}\n";
        echo "Successful: {$metrics['successful']}\n";
        echo "Failed: {$metrics['failed']}\n";
        echo "Fraud Detected: {$metrics['fraud_detected']}\n";
        echo "Circuit Breaker Trips: {$metrics['circuit_breaker_trips']}\n";
        echo "Success Rate: " . number_format($metrics['success_rate'] * 100, 2) . "%\n";
        echo "Total Amount Processed: \${$metrics['total_amount']}\n";
        echo "Average Processing Time: {$metrics['average_processing_time']}s\n";
        echo "System Health: {$systemHealth['overall']}\n";

        echo "\nCircuit Breaker States:\n";
        foreach ($circuitStates as $gateway => $state) {
            echo "  $gateway: $state\n";
        }

        // Save detailed report
        try {
            $processor->saveReportToFile('./reports/enhanced_payment_report_php.json');
            echo "\nDetailed report saved to ./reports/enhanced_payment_report_php.json\n";
        } catch (Exception $e) {
            echo "\nError saving report: {$e->getMessage()}\n";
        }

        // Demonstrate system resilience
        echo "\nEnhanced Chaos Resilience Analysis:\n";
        echo str_repeat("=", 40) . "\n";
        echo "System handled " . number_format($metrics['success_rate'] * 100, 1) . "% success rate under chaos conditions\n";
        echo "Detected and prevented {$metrics['fraud_detected']} fraudulent transactions\n";
        echo "Circuit breakers prevented {$metrics['circuit_breaker_trips']} potential cascade failures\n";
        echo "Processed \${$metrics['total_amount']} in total transaction volume\n";
        echo "Average processing time: {$metrics['average_processing_time']}s\n";
        
        // Show configuration
        echo "\nSystem Configuration:\n";
        echo "Max Retries: {$config->maxRetries}\n";
        echo "Fraud Threshold: {$config->fraudThreshold}\n";
        echo "Max Amount: \${$config->maxAmount}\n";
        echo "Circuit Breaker: {$config->circuitBreakerFailures} failures / {$config->circuitBreakerReset}s reset\n";
        echo "Log Level: {$config->logLevel}\n";

    } catch (Throwable $e) {
        echo "Fatal error in payment processor: " . $e->getMessage() . "\n";
        echo "Stack trace: " . $e->getTraceAsString() . "\n";
        exit(1);
    } finally {
        // Restore original error handler
        restore_error_handler();
    }
}

// Run the demo
if (PHP_SAPI === 'cli') {
    main();
} else {
    echo "This script must be run from the command line.\n";
    exit(1);
}
