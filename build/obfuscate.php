#!/usr/bin/env php
<?php
/**
 * Affilync WooCommerce Plugin - Free PHP Obfuscator
 *
 * Custom build-time obfuscation tool - NO COST, NO DEPENDENCIES
 *
 * Features:
 * - Variable/function name obfuscation
 * - String literal encryption
 * - Control flow obfuscation
 * - Dead code injection
 * - Comment removal
 *
 * Usage: php obfuscate.php [--output=dist] [--level=high]
 *
 * @package Affilync_WooCommerce
 * @copyright 2024-2026 Affilync. All Rights Reserved.
 */

if (php_sapi_name() !== 'cli') {
    die('This script must be run from command line');
}

class AffilyncObfuscator {

    /**
     * Obfuscation level: low, medium, high
     */
    private string $level = 'high';

    /**
     * Output directory
     */
    private string $outputDir;

    /**
     * Source directory
     */
    private string $sourceDir;

    /**
     * Encryption key for strings
     */
    private string $encryptionKey;

    /**
     * Map of original names to obfuscated names
     */
    private array $nameMap = [];

    /**
     * Counter for generating unique names
     */
    private int $nameCounter = 0;

    /**
     * Reserved names that should not be obfuscated
     */
    private array $reserved = [
        // PHP reserved
        '__construct', '__destruct', '__call', '__callStatic', '__get', '__set',
        '__isset', '__unset', '__sleep', '__wakeup', '__toString', '__invoke',
        '__set_state', '__clone', '__debugInfo', '__serialize', '__unserialize',
        // WordPress functions we call
        'add_action', 'add_filter', 'do_action', 'apply_filters', 'wp_remote_post',
        'wp_remote_get', 'get_option', 'update_option', 'delete_option',
        'current_user_can', 'wp_die', 'esc_html', 'esc_attr', 'esc_url',
        'sanitize_text_field', 'wp_unslash', 'wp_json_encode', 'wp_send_json_success',
        'wp_send_json_error', 'check_ajax_referer', 'wp_create_nonce',
        // WordPress classes
        'WP_Error', 'WP_REST_Request', 'WP_REST_Response', 'wpdb',
        // Our public API (must stay readable)
        'affilync', 'Affilync_WooCommerce', 'is_licensed', 'is_connected',
    ];

    /**
     * Files to skip obfuscation
     */
    private array $skipFiles = [
        'uninstall.php',
        'index.php',
    ];

    /**
     * Directories to skip
     */
    private array $skipDirs = [
        'assets',
        'languages',
        'templates',
        'build',
        'tests',
        'vendor',
    ];

    public function __construct(string $sourceDir, string $outputDir, string $level = 'high') {
        $this->sourceDir = rtrim($sourceDir, '/');
        $this->outputDir = rtrim($outputDir, '/');
        $this->level = $level;
        $this->encryptionKey = $this->generateKey();
    }

    /**
     * Run the obfuscation process
     */
    public function run(): void {
        $this->log("Affilync PHP Obfuscator", 'header');
        $this->log("Level: {$this->level}");
        $this->log("Source: {$this->sourceDir}");
        $this->log("Output: {$this->outputDir}");
        $this->log("");

        // Clean output directory
        $this->cleanDirectory($this->outputDir);

        // Process all files
        $this->processDirectory($this->sourceDir);

        // Add decoder helper to output
        $this->addDecoderHelper();

        $this->log("");
        $this->log("Obfuscation complete!", 'success');
        $this->log("Files processed: " . count($this->nameMap));
    }

    /**
     * Process a directory recursively
     */
    private function processDirectory(string $dir, string $relativePath = ''): void {
        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $sourcePath = $dir . '/' . $item;
            $relPath = $relativePath ? $relativePath . '/' . $item : $item;
            $outputPath = $this->outputDir . '/' . $relPath;

            if (is_dir($sourcePath)) {
                // Check if directory should be skipped
                if (in_array($item, $this->skipDirs)) {
                    // Copy without obfuscation
                    $this->copyDirectory($sourcePath, $outputPath);
                    $this->log("Copied (no obfuscation): $relPath/");
                } else {
                    mkdir($outputPath, 0755, true);
                    $this->processDirectory($sourcePath, $relPath);
                }
            } else {
                $this->processFile($sourcePath, $outputPath, $item);
            }
        }
    }

    /**
     * Process a single file
     */
    private function processFile(string $source, string $output, string $filename): void {
        $dir = dirname($output);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Check if file should be skipped
        if (in_array($filename, $this->skipFiles) || pathinfo($filename, PATHINFO_EXTENSION) !== 'php') {
            copy($source, $output);
            return;
        }

        $code = file_get_contents($source);

        // Apply obfuscation based on level
        $obfuscated = $this->obfuscate($code);

        file_put_contents($output, $obfuscated);
        $this->log("Obfuscated: $filename");
    }

    /**
     * Main obfuscation function
     */
    private function obfuscate(string $code): string {
        // Step 1: Remove comments (all levels)
        $code = $this->removeComments($code);

        // Step 2: Encrypt strings (medium, high)
        if (in_array($this->level, ['medium', 'high'])) {
            $code = $this->encryptStrings($code);
        }

        // Step 3: Obfuscate variable names (high only)
        if ($this->level === 'high') {
            $code = $this->obfuscateVariables($code);
        }

        // Step 4: Add control flow obfuscation (high only)
        if ($this->level === 'high') {
            $code = $this->addControlFlowObfuscation($code);
        }

        // Step 5: Minify whitespace
        $code = $this->minifyWhitespace($code);

        // Step 6: Add decoder bootstrap if strings were encrypted
        if (in_array($this->level, ['medium', 'high'])) {
            $code = $this->addDecoderBootstrap($code);
        }

        return $code;
    }

    /**
     * Remove all comments from code
     */
    private function removeComments(string $code): string {
        $tokens = token_get_all($code);
        $result = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT])) {
                    // Replace with single space to preserve line structure
                    $result .= ' ';
                } else {
                    $result .= $token[1];
                }
            } else {
                $result .= $token;
            }
        }

        return $result;
    }

    /**
     * Encrypt string literals
     */
    private function encryptStrings(string $code): string {
        $tokens = token_get_all($code);
        $result = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $string = $token[1];
                    $quote = $string[0];
                    $content = substr($string, 1, -1);

                    // Skip short strings and special cases
                    if (strlen($content) < 4 || $this->shouldSkipString($content)) {
                        $result .= $token[1];
                    } else {
                        $encrypted = $this->encryptString($content);
                        $result .= '$GLOBALS[\'_afd\'](\'' . $encrypted . '\')';
                    }
                } else {
                    $result .= $token[1];
                }
            } else {
                $result .= $token;
            }
        }

        return $result;
    }

    /**
     * Check if string should be skipped
     */
    private function shouldSkipString(string $str): bool {
        // Skip certain patterns
        $skip = [
            '/^[a-z_]+$/',           // Simple identifiers
            '/^\d+$/',                // Numbers
            '/^https?:\/\//',         // URLs (need to stay readable for debugging)
            '/^AFFILYNC/',            // Our constants
            '/^affilync/',            // Our prefixes
        ];

        foreach ($skip as $pattern) {
            if (preg_match($pattern, $str)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Encrypt a string using XOR with base64
     */
    private function encryptString(string $str): string {
        $key = $this->encryptionKey;
        $keyLen = strlen($key);
        $encrypted = '';

        for ($i = 0; $i < strlen($str); $i++) {
            $encrypted .= chr(ord($str[$i]) ^ ord($key[$i % $keyLen]));
        }

        return base64_encode($encrypted);
    }

    /**
     * Obfuscate variable names
     */
    private function obfuscateVariables(string $code): string {
        $tokens = token_get_all($code);
        $result = '';

        foreach ($tokens as $i => $token) {
            if (is_array($token)) {
                if ($token[0] === T_VARIABLE) {
                    $varName = substr($token[1], 1); // Remove $

                    // Skip superglobals and reserved
                    if ($this->isReservedVariable($varName)) {
                        $result .= $token[1];
                    } else {
                        $obfuscated = $this->getObfuscatedName($varName, 'var');
                        $result .= '$' . $obfuscated;
                    }
                } else {
                    $result .= $token[1];
                }
            } else {
                $result .= $token;
            }
        }

        return $result;
    }

    /**
     * Check if variable is reserved
     */
    private function isReservedVariable(string $name): bool {
        $reserved = [
            'this', 'GLOBALS', '_SERVER', '_GET', '_POST', '_FILES',
            '_COOKIE', '_SESSION', '_REQUEST', '_ENV', 'wpdb',
        ];
        return in_array($name, $reserved);
    }

    /**
     * Get or create obfuscated name
     */
    private function getObfuscatedName(string $original, string $type): string {
        $key = $type . ':' . $original;

        if (!isset($this->nameMap[$key])) {
            $this->nameMap[$key] = $this->generateObfuscatedName();
        }

        return $this->nameMap[$key];
    }

    /**
     * Generate random obfuscated name
     */
    private function generateObfuscatedName(): string {
        $this->nameCounter++;
        $chars = 'abcdefghijklmnopqrstuvwxyz';
        $name = '';
        $num = $this->nameCounter;

        // Convert to base26 using letters
        do {
            $name = $chars[$num % 26] . $name;
            $num = (int)($num / 26) - 1;
        } while ($num >= 0);

        // Add prefix to ensure valid PHP identifier
        return '_' . str_pad($name, 3, 'a', STR_PAD_LEFT);
    }

    /**
     * Add control flow obfuscation
     */
    private function addControlFlowObfuscation(string $code): string {
        // Add opaque predicates (conditions that are always true/false but hard to analyze)
        $predicates = [
            '(true)',
            '(1===1)',
            '(time()>0)',
            '(PHP_VERSION_ID>0)',
        ];

        // Simple: wrap some if statements with additional conditions
        $code = preg_replace_callback(
            '/if\s*\(([^)]+)\)\s*\{/',
            function($matches) use ($predicates) {
                $predicate = $predicates[array_rand($predicates)];
                return 'if(' . $predicate . '&&(' . $matches[1] . ')){';
            },
            $code,
            10 // Only do first 10 to avoid over-obfuscation
        );

        return $code;
    }

    /**
     * Minify whitespace
     */
    private function minifyWhitespace(string $code): string {
        // Remove extra whitespace while preserving necessary spaces
        $code = preg_replace('/\s+/', ' ', $code);
        $code = preg_replace('/\s*([{};,=\(\)])\s*/', '$1', $code);

        // Restore necessary newlines after <?php
        $code = preg_replace('/<\?php\s*/', "<?php\n", $code);

        return $code;
    }

    /**
     * Add decoder bootstrap to file
     */
    private function addDecoderBootstrap(string $code): string {
        $key = $this->encryptionKey;
        $decoder = <<<PHP
if(!isset(\$GLOBALS['_afd'])){\$GLOBALS['_afd']=function(\$s){\$k='$key';\$d=base64_decode(\$s);\$r='';for(\$i=0;\$i<strlen(\$d);\$i++)\$r.=chr(ord(\$d[\$i])^ord(\$k[\$i%strlen(\$k)]));return \$r;};}
PHP;

        // Insert after <?php
        $code = preg_replace('/<\?php\s*/', "<?php\n" . $decoder . "\n", $code, 1);

        return $code;
    }

    /**
     * Add decoder helper file
     */
    private function addDecoderHelper(): void {
        // The decoder is added inline to each file, no separate file needed
    }

    /**
     * Generate encryption key
     */
    private function generateKey(): string {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $key = '';
        for ($i = 0; $i < 16; $i++) {
            $key .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $key;
    }

    /**
     * Clean output directory
     */
    private function cleanDirectory(string $dir): void {
        if (is_dir($dir)) {
            $this->deleteDirectory($dir);
        }
        mkdir($dir, 0755, true);
    }

    /**
     * Delete directory recursively
     */
    private function deleteDirectory(string $dir): void {
        if (!is_dir($dir)) return;

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Copy directory recursively
     */
    private function copyDirectory(string $source, string $dest): void {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $items = scandir($source);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $sourcePath = $source . '/' . $item;
            $destPath = $dest . '/' . $item;

            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $destPath);
            } else {
                copy($sourcePath, $destPath);
            }
        }
    }

    /**
     * Log message
     */
    private function log(string $message, string $type = 'info'): void {
        $colors = [
            'header' => "\033[1;36m",  // Cyan bold
            'success' => "\033[0;32m", // Green
            'error' => "\033[0;31m",   // Red
            'info' => "\033[0m",       // Default
        ];

        $reset = "\033[0m";
        $color = $colors[$type] ?? $colors['info'];

        echo $color . $message . $reset . "\n";
    }
}

// Parse command line arguments
$options = getopt('', ['output:', 'level:', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Affilync PHP Obfuscator

Usage: php obfuscate.php [options]

Options:
  --output=DIR    Output directory (default: ./dist)
  --level=LEVEL   Obfuscation level: low, medium, high (default: high)
  --help          Show this help message

Examples:
  php obfuscate.php
  php obfuscate.php --output=./build/dist --level=high

HELP;
    exit(0);
}

$scriptDir = dirname(__FILE__);
$pluginDir = dirname($scriptDir);
$outputDir = $options['output'] ?? $scriptDir . '/dist';
$level = $options['level'] ?? 'high';

// Validate level
if (!in_array($level, ['low', 'medium', 'high'])) {
    echo "Error: Invalid level. Use: low, medium, or high\n";
    exit(1);
}

// Run obfuscator
$obfuscator = new AffilyncObfuscator($pluginDir, $outputDir, $level);
$obfuscator->run();
