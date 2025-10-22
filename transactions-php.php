<?php

/**
 * Enhanced Payment Processing System with Chaos Engineering - PHP Version
 */

// Configuration management
class Config {
    public float $baseFailureRate = 0.05;
    public int $maxRetries = 2;
    public float $gatewayTimeout = 30.0;
    public float $fraudThreshold = 0.7;
    public int $circuitBreakerFailures = 5;
    public float $circuitBreakerReset = 60.0;

    public static function loadConfig(): self {
        return new self();
    }
}

// Enhanced error handling - Fixed: Don't redeclare properties from Exception
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

    public function validate(): void {
        if ($this->amount <= 0) {
            throw PaymentErrors::invalidAmount();
        }
        
        $validCurrencies = ['USD', 'EUR', 'GBP', 'CAD'];
        if (!in_array($this->currency, $validCurrencies)) {
            throw PaymentErrors::invalidCurrency();
        }
        
        if (empty($this->merchantId)) {
            throw new PaymentError('INVALID_MERCHANT', 'Merchant ID is required', '', false);
        }
        
        if (empty($this->customerId)) {
            throw new PaymentError('INVALID_CUSTOMER', 'Customer ID is required', '', false);
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
                    return true;
                }
                return false;
            }
            return true;
        } finally {
            flock($this->lock, LOCK_UN);
        }
    }

    public function recordSuccess(): void {
        flock($this->lock, LOCK_EX);
        try {
            $this->failures = 0;
        } finally {
            flock($this->lock, LOCK_UN);
        }
    }

    public function recordFailure(): void {
        flock($this->lock, LOCK_EX);
        try {
            $this->failures++;
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

// Core business service
class PaymentProcessor {
    private Config $config;
    private array $gateways;
    private FraudDetectionService $fraudService;
    private ChaosInjector $chaosInjector;
    private array $circuitBreakers = [];
    private array $transactionHistory = [];
    private array $idempotencyStore = [];
    private PaymentMetrics $metrics;
    private $lock;

    public function __construct(?Config $config = null) {
        $this->config = $config ?? Config::loadConfig();
        
        $this->gateways = [
            new PaymentGateway('Stripe', 0.98, 0.2),   // 200ms latency
            new PaymentGateway('PayPal', 0.96, 0.3),   // 300ms latency
            new PaymentGateway('Square', 0.97, 0.25),  // 250ms latency
            new PaymentGateway('Adyen', 0.99, 0.15),   // 150ms latency
        ];
        
        $this->fraudService = new FraudDetectionService($this->config);
        $this->chaosInjector = new ChaosInjector($this->config);
        $this->metrics = new PaymentMetrics();
        $this->lock = fopen('php://temp', 'r+');
        
        // Initialize circuit breakers for each gateway
        foreach ($this->gateways as $gateway) {
            $this->circuitBreakers[$gateway->name] = new CircuitBreaker(
                $this->config->circuitBreakerFailures,
                $this->config->circuitBreakerReset
            );
        }
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
            $existingPayment = $this->getIdempotentPayment($idempotencyKey);
            if ($existingPayment) {
                error_log("Returning existing payment for idempotency key: $idempotencyKey");
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

        // Validate payment
        try {
            $payment->validate();
        } catch (PaymentError $e) {
            return $this->handlePaymentFailure($payment, $e, $startTime);
        }

        // Store payment
        flock($this->lock, LOCK_EX);
        try {
            $this->transactionHistory[$payment->id] = $payment;
            if (!empty($idempotencyKey)) {
                $this->idempotencyStore[$idempotencyKey] = $payment;
            }
        } finally {
            flock($this->lock, LOCK_UN);
        }

        error_log("Processing payment {$payment->id}: \$$amount from $customerId to $merchantId");

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
                return $this->handlePaymentFailure($payment, $circuitError, $startTime);
            }

            $payment->status = 'processing';

            // Process with gateway
            list($success, $processError) = $this->processWithGateway($payment, $gateway);
            
            if ($processError) {
                $circuitBreaker->recordFailure();
                flock($this->lock, LOCK_EX);
                $this->metrics->circuitBreakerTrips++;
                flock($this->lock, LOCK_UN);
                return $this->handlePaymentFailure($payment, $processError, $startTime);
            }

            if (!$success) {
                // Retry logic
                if ($payment->retryCount < $this->config->maxRetries) {
                    $payment->retryCount++;
                    error_log("Retrying payment {$payment->id} (attempt {$payment->retryCount})");
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
                return $this->handlePaymentFailure($payment, $gatewayError, $startTime);
            }

        } catch (Exception $e) {
            if (!$e instanceof PaymentError) {
                $e = new PaymentError('UNEXPECTED_ERROR', $e->getMessage(), $payment->id, true);
            }
            return $this->handlePaymentFailure($payment, $e, $startTime);
        }
    }

    private function processWithGateway(Payment $payment, PaymentGateway $gateway): array {
        try {
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
            
            return [$success, null];

        } catch (Exception $e) {
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
                error_log('No active gateways available, using fallback');
                return $this->gateways[0];
            }

            return $activeGateways[array_rand($activeGateways)];
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

        error_log("Payment {$payment->id} completed successfully via $gateway (took {$processingTime}s)");
        return $payment;
    }

    private function handlePaymentFailure(Payment $payment, PaymentError $error, float $startTime): Payment {
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

        error_log("Payment {$payment->id} failed: $error");
        return $payment;
    }

    private function getIdempotentPayment(string $key): ?Payment {
        flock($this->lock, LOCK_EX);
        try {
            return $this->idempotencyStore[$key] ?? null;
        } finally {
            flock($this->lock, LOCK_UN);
        }
    }

    public function generateBusinessReport(): array {
        flock($this->lock, LOCK_EX);
        try {
            $revenueByMerchant = [];
            $gatewayStats = [];
            $circuitStates = [];

            foreach ($this->transactionHistory as $payment) {
                if ($payment->status === 'completed') {
                    $revenueByMerchant[$payment->merchantId] = 
                        ($revenueByMerchant[$payment->merchantId] ?? 0.0) + $payment->amount;
                }
            }

            // Get circuit breaker states
            foreach ($this->circuitBreakers as $gatewayName => $circuitBreaker) {
                $circuitStates[$gatewayName] = $circuitBreaker->state();
            }

            return [
                'metrics' => $this->metrics->toArray(),
                'revenue_by_merchant' => $revenueByMerchant,
                'gateway_stats' => $gatewayStats,
                'circuit_breaker_states' => $circuitStates,
                'total_transactions' => count($this->transactionHistory),
                'timestamp' => (new DateTime())->format(DateTime::ATOM)
            ];
        } finally {
            flock($this->lock, LOCK_UN);
        }
    }

    public function saveReportToFile(string $filename): void {
        $report = $this->generateBusinessReport();
        
        file_put_contents($filename, json_encode($report, JSON_PRETTY_PRINT));
        
        error_log("Report saved to $filename");
    }

    private function generatePaymentId(): string {
        return 'pay_' . (int)(microtime(true) * 1000) . '_' . mt_rand(1000, 9999);
    }
}

// Fraud Detection Service
class FraudDetectionService {
    private Config $config;
    private array $riskPatterns;

    public function __construct(Config $config) {
        $this->config = $config;
        $this->riskPatterns = [
            'high_amount_velocity',
            'unusual_geolocation',
            'suspicious_device',
            'risky_merchant_category',
            'card_testing_pattern',
        ];
    }

    public function checkPayment(Payment $payment): FraudDetectionResult {
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

        $isFraudulent = $riskScore > $this->config->fraudThreshold;

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

    public function __construct(Config $config) {
        $this->config = $config;
        $this->failureRate = $config->baseFailureRate;
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
            error_log("Simulating gateway outage: $gateway");
        }

        // Random latency spikes
        if (mt_rand() / mt_getrandmax() < 0.03) { // 3% chance of high latency
            $latency = mt_rand() / mt_getrandmax() * $this->latencyRange;
            usleep((int)($latency * 1000000));
            error_log("Injected latency: {$latency}s");
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

// Demo execution
function main() {
    echo "Enhanced Payment Processing System with Chaos Engineering - PHP Version\n";
    echo str_repeat("=", 80) . "\n";

    $config = Config::loadConfig();
    $processor = new PaymentProcessor($config);

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

    echo "Total Processed: {$metrics['total_processed']}\n";
    echo "Successful: {$metrics['successful']}\n";
    echo "Failed: {$metrics['failed']}\n";
    echo "Fraud Detected: {$metrics['fraud_detected']}\n";
    echo "Circuit Breaker Trips: {$metrics['circuit_breaker_trips']}\n";
    echo "Success Rate: " . number_format($metrics['success_rate'] * 100, 2) . "%\n";
    echo "Total Amount Processed: \${$metrics['total_amount']}\n";
    echo "Average Processing Time: {$metrics['average_processing_time']}s\n";

    echo "\nCircuit Breaker States:\n";
    foreach ($circuitStates as $gateway => $state) {
        echo "  $gateway: $state\n";
    }

    // Save detailed report
    try {
        $processor->saveReportToFile('enhanced_payment_report_php.json');
        echo "\nDetailed report saved to enhanced_payment_report_php.json\n";
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
    echo "Circuit Breaker: {$config->circuitBreakerFailures} failures / {$config->circuitBreakerReset}s reset\n";
}

// Run the demo
if (PHP_SAPI === 'cli') {
    main();
} else {
    echo "This script must be run from the command line.\n";
    exit(1);
}
