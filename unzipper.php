<?php
/**
 * The Unzipper — a single-file archive workspace.
 * 
 * @author  Thomas Gassmann rework from Andreas Tasch work
 * @license GNU GPL v3
 * @version 0.2.0
 */
declare(strict_types=1);

const VERSION = '0.2.0';
const MAX_ENTRIES = 10000;
const MAX_BYTES = 536870912; // 512 MiB of uncompressed data per operation.

function escape(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function postString(string $key, string $default = ''): string
{
  if (!isset($_POST[$key])) {
    return $default;
  }
  if (!is_string($_POST[$key]) || strlen($_POST[$key]) > 4096) {
    throw new RuntimeException('Invalid form value. Reload the page and try again.');
  }
  return $_POST[$key];
}

function formatBytes(int $bytes): string
{
  return $bytes < 1048576 ? round($bytes / 1024, 1) . ' KB' : round($bytes / 1048576, 1) . ' MB';
}

final class ArchiveWorkspace
{
  private string $root;
  private int $bytes = 0;
  private int $entries = 0;

  public function __construct(string $root)
  {
    $this->root = realpath($root) ?: throw new RuntimeException('Workspace is unavailable.');
  }

  public function archives(): array
  {
    $archives = [];
    foreach (new DirectoryIterator($this->root) as $entry) {
      if ($entry->isFile() && !$entry->isLink() && preg_match('/\.(zip|rar|gz|tgz)$/i', $entry->getFilename())) {
        $archives[$entry->getFilename()] = $entry->getSize();
      }
    }
    ksort($archives, SORT_NATURAL | SORT_FLAG_CASE);
    return $archives;
  }

  /** Reject ambiguous portable paths before they ever reach a filesystem API. */
  private function relative(string $path, bool $allowRoot = false): string
  {
    if ($allowRoot && ($path === '' || $path === '.')) {
      return '';
    }
    if ($path === '' || strlen($path) > 2048 || preg_match('/[\\\\:\x00-\x1f\x7f]/', $path) || $path[0] === '/') {
      throw new RuntimeException('Use a relative path inside this workspace, without slashes at the start, backslashes or control characters.');
    }
    $parts = explode('/', rtrim($path, '/'));
    foreach ($parts as $part) {
      if (
        $part === '' || $part === '.' || $part === '..' || preg_match('/[. ]$/', $part)
        || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $part)
      ) {
        throw new RuntimeException('Unsafe path: parent traversal, ambiguous names and special device names are blocked.');
      }
    }
    return implode('/', $parts);
  }

  private function contained(string $path): bool
  {
    return $path === $this->root || str_starts_with($path, $this->root . DIRECTORY_SEPARATOR);
  }

  /** Check every existing component; realpath alone would hide symbolic links. */
  private function local(string $relative): string
  {
    $path = $this->root;
    foreach ($relative === '' ? [] : explode('/', $relative) as $part) {
      $path .= DIRECTORY_SEPARATOR . $part;
      clearstatcache(true, $path);
      if (is_link($path)) {
        throw new RuntimeException('Symbolic links are not allowed in workspace paths.');
      }
      if (file_exists($path) && !$this->contained(realpath($path) ?: '')) {
        throw new RuntimeException('The requested path is outside this workspace.');
      }
    }
    return $path;
  }

  private function countEntry(int $size): void
  {
    if ($size < 0 || ++$this->entries > MAX_ENTRIES || $size > MAX_BYTES - $this->bytes) {
      throw new RuntimeException('Archive exceeds the safety limit of 10,000 entries or 512 MB of expanded data.');
    }
    $this->bytes += $size;
  }

  private function tempDirectory(): string
  {
    $path = sys_get_temp_dir() . '/unzipper-' . bin2hex(random_bytes(16));
    if (!mkdir($path, 0700)) {
      throw new RuntimeException('Cannot create temporary workspace.');
    }
    return $path;
  }

  private function removeTemporary(string $path): void
  {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
      $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
  }

  private function writeAll($output, string $chunk): void
  {
    while ($chunk !== '') {
      $written = fwrite($output, $chunk);
      if ($written === false || $written === 0) {
        throw new RuntimeException('Could not write archive data. Check available disk space.');
      }
      $chunk = substr($chunk, $written);
    }
  }

  /** Both advertised and actual byte counts are bounded. Never overwrite. */
  private function copyStream($input, string $target, int $size): void
  {
    $output = fopen($target, 'xb');
    if ($output === false) {
      throw new RuntimeException('Cannot create a file, or a file already exists at the destination.');
    }
    $complete = false;
    try {
      $remaining = $size;
      while ($remaining > 0) {
        $chunk = fread($input, min(65536, $remaining));
        if ($chunk === false || $chunk === '') {
          throw new RuntimeException('Archive data is incomplete or unreadable.');
        }
        $this->writeAll($output, $chunk);
        $remaining -= strlen($chunk);
      }
      $complete = true;
    } finally {
      fclose($output);
      if (!$complete) {
        unlink($target);
      }
    }
  }

  private function stagePath(string $stage, string $name, bool $directory): string
  {
    $name = $this->relative($name);
    $path = $stage . '/' . $name;
    $parent = $directory ? $path : dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0700, true)) {
      throw new RuntimeException('Cannot prepare archive directories.');
    }
    if (!$directory && file_exists($path)) {
      throw new RuntimeException('The archive contains duplicate or conflicting paths.');
    }
    return $path;
  }

  private function extractZip(string $archive, string $stage): void
  {
    if (!class_exists('ZipArchive')) {
      throw new RuntimeException('ZIP support is unavailable. Enable the PHP zip extension.');
    }
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
      throw new RuntimeException('Cannot open ZIP archive. It may be damaged.');
    }
    try {
      for ($index = 0; $index < $zip->numFiles; $index++) {
        $stat = $zip->statIndex($index);
        if ($stat === false || !$zip->getExternalAttributesIndex($index, $system, $attributes)) {
          throw new RuntimeException('Cannot inspect ZIP entries.');
        }
        $type = ($attributes >> 16) & 0170000;
        if ($system === ZipArchive::OPSYS_UNIX && !in_array($type, [0, 0100000, 0040000], true)) {
          throw new RuntimeException('Archive links and special files are blocked.');
        }
        if (!empty($stat['encryption_method'])) {
          throw new RuntimeException('Password-encrypted archives are not supported.');
        }
        $directory = str_ends_with($stat['name'], '/');
        $this->countEntry((int) $stat['size']);
        $target = $this->stagePath($stage, $stat['name'], $directory);
        if (!$directory) {
          $input = $zip->getStreamIndex($index);
          if ($input === false) {
            throw new RuntimeException('Cannot read a ZIP entry.');
          }
          try {
            $this->copyStream($input, $target, (int) $stat['size']);
            if (fread($input, 1) !== '') {
              throw new RuntimeException('ZIP entry exceeds its declared size.');
            }
            if (strtolower(hash_file('crc32b', $target)) !== sprintf('%08x', $stat['crc'])) {
              throw new RuntimeException('ZIP integrity check failed.');
            }
          } finally {
            fclose($input);
          }
        }
      }
    } finally {
      $zip->close();
    }
  }

  private function extractRar(string $archive, string $stage): void
  {
    if (!class_exists('RarArchive')) {
      throw new RuntimeException('RAR support is unavailable. Enable the PHP rar extension.');
    }
    $rar = RarArchive::open($archive);
    if ($rar === false) {
      throw new RuntimeException('Cannot open RAR archive.');
    }
    try {
      $entries = $rar->getEntries();
      if ($entries === false || count($entries) > MAX_ENTRIES) {
        throw new RuntimeException('Cannot inspect RAR entries.');
      }
      foreach ($entries as $entry) {
        // Only Unix regular files/directories and DOS/Windows entries are understood.
        $host = $entry->getHostOs();
        $type = $entry->getAttr() & 0170000;
        if (!in_array($host, [0, 2, 3], true) || ($host === 3 && !in_array($type, [0100000, 0040000], true))) {
          throw new RuntimeException('RAR links, special files or unsupported host attributes are blocked.');
        }
        if ($entry->isEncrypted() || ($host !== 3 && ($entry->getAttr() & 0x400))) {
          throw new RuntimeException('Encrypted RAR entries and reparse points are blocked.');
        }
        $this->countEntry($entry->getUnpackedSize());
        $target = $this->stagePath($stage, $entry->getName(), $entry->isDirectory());
        if (!$entry->isDirectory()) {
          $input = $entry->getStream();
          if ($input === false) {
            throw new RuntimeException('Cannot read RAR entry. Encrypted archives are not supported.');
          }
          try {
            $this->copyStream($input, $target, $entry->getUnpackedSize());
            if (fread($input, 1) !== '' || strtolower(hash_file('crc32b', $target)) !== strtolower(str_pad($entry->getCrc(), 8, '0', STR_PAD_LEFT))) {
              throw new RuntimeException('RAR integrity check failed.');
            }
          } finally {
            fclose($input);
          }
        }
      }
    } finally {
      $rar->close();
    }
  }

  /** Read bounded GZIP data first; raw TAR metadata is checked before extraction. */
  private function extractGzip(string $archive, string $stage): void
  {
    if (!function_exists('gzopen')) {
      throw new RuntimeException('GZIP support is unavailable. Enable PHP zlib.');
    }
    if (file_get_contents($archive, false, null, 0, 2) !== "\x1f\x8b") {
      throw new RuntimeException('This file is not a valid GZIP archive.');
    }
    $archiveSize = filesize($archive);
    if ($archiveSize < 18) {
      throw new RuntimeException('GZIP header or footer is incomplete.');
    }
    $footer = unpack('Vcrc/Vsize', file_get_contents($archive, false, null, $archiveSize - 8, 8));
    $hash = hash_init('crc32b');
    $input = gzopen($archive, 'rb');
    if ($input === false) {
      throw new RuntimeException('Cannot open GZIP archive.');
    }
    $temporary = tempnam(sys_get_temp_dir(), 'unzipper-gz-');
    if ($temporary === false) {
      gzclose($input);
      throw new RuntimeException('Cannot prepare GZIP data.');
    }
    $output = fopen($temporary, 'wb');
    try {
      if ($output === false) {
        throw new RuntimeException('Cannot prepare GZIP data.');
      }
      $size = 0;
      while (!gzeof($input)) {
        $chunk = gzread($input, 65536);
        if ($chunk === false || ($chunk === '' && !gzeof($input))) {
          throw new RuntimeException('GZIP data is damaged.');
        }
        hash_update($hash, $chunk);
        $size += strlen($chunk);
        if ($size > MAX_BYTES) {
          throw new RuntimeException('Expanded GZIP data exceeds the 512 MB safety limit.');
        }
        $this->writeAll($output, $chunk);
      }
      if ($size !== $footer['size'] || hash_final($hash) !== sprintf('%08x', $footer['crc'])) {
        throw new RuntimeException('GZIP integrity check failed. Truncated or multi-member streams are not supported.');
      }
      fclose($output);
      $output = false;
      if (preg_match('/\.(tar\.gz|tgz)$/i', $archive)) {
        $this->extractTar($temporary, $stage);
      } else {
        $this->countEntry($size);
        $target = $this->stagePath($stage, substr(basename($archive), 0, -3), false);
        if (!rename($temporary, $target)) {
          throw new RuntimeException('Cannot prepare decompressed file.');
        }
      }
    } finally {
      gzclose($input);
      if (is_resource($output)) {
        fclose($output);
      }
      if (file_exists($temporary)) {
        unlink($temporary);
      }
    }
  }

  private function tarNumber(string $value): int
  {
    $value = trim($value, " \0");
    if ($value !== '' && !preg_match('/^[0-7]+$/D', $value)) {
      throw new RuntimeException('Unsupported TAR numeric format.');
    }
    $number = $value === '' ? 0 : octdec($value);
    if ($number > MAX_BYTES) {
      throw new RuntimeException('TAR entry exceeds the safety limit.');
    }
    return (int) $number;
  }

  private function extractTar(string $archive, string $stage): void
  {
    $input = fopen($archive, 'rb');
    if ($input === false) {
      throw new RuntimeException('Cannot read TAR data.');
    }
    $pax = [];
    $longName = null;
    $metadataCount = 0;
    try {
      while (($header = fread($input, 512)) !== '') {
        if (strlen($header) !== 512) {
          throw new RuntimeException('TAR header is incomplete.');
        }
        if ($header === str_repeat("\0", 512)) {
          break;
        }
        $checksum = array_sum(unpack('C*', substr_replace($header, str_repeat(' ', 8), 148, 8)));
        if ($this->tarNumber(substr($header, 148, 8)) !== $checksum) {
          throw new RuntimeException('TAR header integrity check failed.');
        }
        $name = rtrim(substr($header, 0, 100), "\0");
        if (substr($header, 257, 5) === 'ustar') {
          $prefix = rtrim(substr($header, 345, 155), "\0");
          $name = ($prefix === '' ? '' : $prefix . '/') . $name;
        }
        $size = $this->tarNumber(substr($header, 124, 12));
        $type = $header[156];
        if (in_array($type, ['x', 'L'], true)) {
          if (++$metadataCount > MAX_ENTRIES || $size > 65536) {
            throw new RuntimeException('TAR metadata exceeds the safety limit.');
          }
          $data = $size === 0 ? '' : fread($input, $size);
          if (strlen($data) !== $size) {
            throw new RuntimeException('TAR metadata is incomplete.');
          }
          if ($type === 'L') {
            $longName = rtrim($data, "\0");
          } else {
            $offset = 0;
            while ($offset < strlen($data)) {
              if (!preg_match('/\G([0-9]+) /', $data, $match, 0, $offset)) {
                throw new RuntimeException('Invalid TAR extended metadata.');
              }
              $length = (int) $match[1];
              if ($length <= strlen($match[0]) || $length > strlen($data) - $offset) {
                throw new RuntimeException('Invalid TAR metadata length.');
              }
              $record = substr($data, $offset + strlen($match[0]), $length - strlen($match[0]) - 1);
              $pair = explode('=', $record, 2);
              if (count($pair) !== 2 || str_contains($pair[0], 'sparse') || $pair[0] === 'linkpath') {
                throw new RuntimeException('TAR sparse files and links are blocked.');
              }
              $pax[$pair[0]] = $pair[1];
              $offset += $length;
            }
          }
        } else {
          if (!in_array($type, ["\0", '0', '5'], true)) {
            throw new RuntimeException('TAR links, special files and unsupported extensions are blocked.');
          }
          $name = $pax['path'] ?? $longName ?? $name;
          if (isset($pax['size'])) {
            if (!ctype_digit($pax['size']) || (float) $pax['size'] > MAX_BYTES) {
              throw new RuntimeException('Invalid TAR expanded size.');
            }
            $size = (int) $pax['size'];
          }
          $pax = [];
          $longName = null;
          // Many tar tools include a harmless root-directory entry.
          if (!($type === '5' && in_array($name, ['.', './'], true))) {
            while (str_starts_with($name, './')) {
              $name = substr($name, 2);
            }
            $this->countEntry($size);
            $target = $this->stagePath($stage, $name, $type === '5');
            if ($type === '5' && $size !== 0) {
              throw new RuntimeException('Invalid TAR directory data.');
            }
            if ($type !== '5') {
              $this->copyStream($input, $target, $size);
            }
          }
        }
        $padding = (512 - $size % 512) % 512;
        if ($padding > 0 && strlen(fread($input, $padding)) !== $padding) {
          throw new RuntimeException('TAR padding is incomplete.');
        }
      }
      if ($pax !== [] || $longName !== null) {
        throw new RuntimeException('TAR metadata has no corresponding file.');
      }
    } finally {
      fclose($input);
    }
  }

  public function extract(string $archive, string $destination): array
  {
    $this->bytes = $this->entries = 0;
    if (!array_key_exists($archive, $this->archives())) {
      throw new RuntimeException('Select an existing archive from this workspace.');
    }
    $destination = $this->relative(trim($destination), true);
    $source = $this->local($this->relative($archive));
    if (filesize($source) > MAX_BYTES) {
      throw new RuntimeException('Archive file exceeds the 512 MB safety limit.');
    }
    $stage = $this->tempDirectory();
    $createdFiles = [];
    $createdDirectories = [];
    try {
      $extension = strtolower(pathinfo($archive, PATHINFO_EXTENSION));
      match ($extension) {
        'zip' => $this->extractZip($source, $stage),
        'rar' => $this->extractRar($source, $stage),
        'gz', 'tgz' => $this->extractGzip($source, $stage),
        default => throw new RuntimeException('Unsupported archive type.')
      };
      $files = [];
      $directories = $destination === '' ? [] : [$destination];
      $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
      foreach ($iterator as $entry) {
        $name = substr($entry->getPathname(), strlen($stage) + 1);
        $relative = ($destination === '' ? '' : $destination . '/') . $name;
        $target = $this->local($relative);
        if ($entry->isDir()) {
          $directories[] = $relative;
        } else {
          if (file_exists($target)) {
            throw new RuntimeException('A destination file already exists. Choose a new extraction folder; existing files are never overwritten.');
          }
          $files[$relative] = $entry->getPathname();
        }
      }
      // Create parents one component at a time, rechecking link confinement.
      foreach ($directories as $relative) {
        $parts = explode('/', $relative);
        $built = '';
        foreach ($parts as $part) {
          $built .= ($built === '' ? '' : '/') . $part;
          $target = $this->local($built);
          if (!is_dir($target)) {
            if (!mkdir($target, 0755)) {
              throw new RuntimeException('Cannot create extraction folder. Check directory permissions.');
            }
            $createdDirectories[] = $target;
          }
        }
      }
      foreach ($files as $relative => $staged) {
        $target = $this->local($relative);
        $input = fopen($staged, 'rb');
        if ($input === false) {
          throw new RuntimeException('Cannot read staged archive data.');
        }
        try {
          // Reserve exclusively so neither existing files nor links can be replaced.
          $output = fopen($target, 'xb');
          if ($output === false) {
            throw new RuntimeException('Cannot create destination file. Existing files are never overwritten.');
          }
          $createdFiles[] = $target;
          try {
            if (stream_copy_to_stream($input, $output) !== filesize($staged)) {
              throw new RuntimeException('Cannot finish writing extracted data. Check available disk space.');
            }
          } finally {
            fclose($output);
          }
        } finally {
          fclose($input);
        }
      }
      return ['message' => 'Archive extracted successfully.', 'files' => count($files), 'bytes' => $this->bytes, 'path' => $destination === '' ? '.' : $destination];
    } catch (Throwable $error) {
      foreach (array_reverse($createdFiles) as $path) {
        unlink($path);
      }
      foreach (array_reverse($createdDirectories) as $path) {
        rmdir($path);
      }
      throw $error;
    } finally {
      $this->removeTemporary($stage);
    }
  }

  public function create(string $source): array
  {
    if (!class_exists('ZipArchive')) {
      throw new RuntimeException('ZIP support is unavailable. Enable the PHP zip extension.');
    }
    $this->bytes = $this->entries = 0;
    $source = $this->relative(trim($source), true);
    $directory = $this->local($source);
    if (!is_dir($directory) || !is_readable($directory)) {
      throw new RuntimeException('Choose a readable directory inside this workspace.');
    }
    $stage = $this->tempDirectory();
    $zip = new ZipArchive();
    $opened = false;
    try {
      if ($zip->open($stage . '/archive.zip', ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('Cannot create ZIP archive.');
      }
      $opened = true;
      $files = 0;
      $skipped = 0;
      mkdir($stage . '/files', 0700);
      $filter = new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), function ($entry) use (&$skipped): bool {
        $this->countEntry(0); // Bound traversal even when everything is excluded.
        if (
          $entry->isLink() || str_starts_with($entry->getFilename(), '.')
          || $entry->getRealPath() === __FILE__ || preg_match('/\.(zip|rar|gz|tgz)$/i', $entry->getFilename())
        ) {
          $skipped++;
          return false;
        }
        return true;
      });
      foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST) as $entry) {
        $path = $entry->getPathname();
        $relative = substr($path, strlen($directory) + 1);
        $this->relative($relative);
        $this->local(($source === '' ? '' : $source . '/') . $relative);
        if ($entry->isDir()) {
          if (!$zip->addEmptyDir($relative)) {
            throw new RuntimeException('Cannot add a directory to ZIP.');
          }
        } elseif ($entry->isFile() && $entry->isReadable()) {
          $size = $entry->getSize();
          if ($size > MAX_BYTES - $this->bytes) {
            throw new RuntimeException('Source exceeds the 512 MB safety limit.');
          }
          $this->bytes += $size;
          $snapshot = $stage . '/files/' . $files;
          $input = fopen($path, 'rb');
          if ($input === false) {
            throw new RuntimeException('Cannot read source file.');
          }
          try {
            $this->copyStream($input, $snapshot, $size);
            if (fread($input, 1) !== '') {
              throw new RuntimeException('A source file changed while creating the archive. Try again when files are stable.');
            }
          } finally {
            fclose($input);
          }
          if (!$zip->addFile($snapshot, $relative)) {
            throw new RuntimeException('Cannot add a file to ZIP.');
          }
          $files++;
        } else {
          throw new RuntimeException('Source contains an unreadable or special file.');
        }
      }
      // libzip removes completely empty archives rather than writing a usable file.
      if ($zip->numFiles === 0) {
        throw new RuntimeException('No eligible files or folders to archive. Dotfiles, links, archives and this utility are excluded.');
      }
      $closed = $zip->close();
      $opened = false;
      if (!$closed) {
        throw new RuntimeException('Could not finish ZIP archive. Check disk space and source permissions.');
      }
      $name = 'zipper-' . date('Y-m-d--H-i-s') . '-' . bin2hex(random_bytes(4)) . '.zip';
      $input = fopen($stage . '/archive.zip', 'rb');
      if ($input === false) {
        throw new RuntimeException('Cannot read created ZIP archive.');
      }
      $target = $this->local($name);
      try {
        $this->copyStream($input, $target, filesize($stage . '/archive.zip'));
      } finally {
        fclose($input);
      }
      return ['message' => 'ZIP archive created successfully.', 'files' => $files, 'bytes' => $this->bytes, 'path' => $name, 'skipped' => $skipped];
    } finally {
      if ($opened) {
        $zip->close();
      }
      $this->removeTemporary($stage);
    }
  }
}

// Tests can load the archive engine without starting an HTTP session.
if (defined('UNZIPPER_LIBRARY_ONLY') && UNZIPPER_LIBRARY_ONLY) {
  return;
}

ini_set('display_errors', '0');
$nonce = base64_encode(random_bytes(24));
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

// All deployments fail closed until an operator supplies a password.
$password = getenv('UNZIPPER_PASSWORD');
if (is_string($password) && $password !== '') {
  if (!hash_equals($password, $_SERVER['PHP_AUTH_PW'] ?? '')) {
    header('WWW-Authenticate: Basic realm="Unzipper", charset="UTF-8"');
    http_response_code(401);
    exit('Authentication required. Use your configured Unzipper password.');
  }
} else {
  http_response_code(403);
  exit('Access is disabled. Set the UNZIPPER_PASSWORD server environment variable and use HTTPS before accessing this utility remotely.');
}
unset($password);

session_name('unzipper_session');
session_start([
  'use_strict_mode' => true,
  'use_only_cookies' => true,
  'cookie_httponly' => true,
  'cookie_samesite' => 'Strict',
  'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];
$workspace = new ArchiveWorkspace(__DIR__);
$status = ['type' => 'info', 'message' => 'Ready when you are. Select an archive or create a new ZIP.'];
$result = null;
$time = 0;
$archiveValue = '';
$extractValue = '';
$sourceValue = '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
  header('Allow: GET, POST');
  http_response_code(405);
  exit('Method not allowed.');
}

if ($method === 'POST') {
  $start = microtime(true);
  $lock = null;
  $hasLock = false;
  $json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
  try {
    if (!hash_equals($csrf, postString('csrf'))) {
      http_response_code(403);
      throw new RuntimeException('Your session has expired. Reload this page before trying again.');
    }
    session_write_close();
    $action = postString('action');
    if (!in_array($action, ['extract', 'create'], true)) {
      throw new RuntimeException('Choose a valid archive operation.');
    }
    $archiveValue = postString('zipfile');
    $extractValue = postString('extpath');
    $sourceValue = postString('zippath');
    $lock = fopen(sys_get_temp_dir() . '/unzipper-' . hash('sha256', __DIR__) . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
      http_response_code(409);
      throw new RuntimeException('Another archive operation is running. Wait for it to finish before trying again.');
    }
    $hasLock = true;
    set_error_handler(static function (int $severity, string $message): never {
      error_log('Unzipper: ' . $message);
      throw new RuntimeException('The operation could not finish. Check file permissions, archive integrity and available disk space.');
    });
    try {
      $result = $action === 'extract' ? $workspace->extract($archiveValue, $extractValue) : $workspace->create($sourceValue);
    } finally {
      restore_error_handler();
    }
    $status = ['type' => 'success', 'message' => $result['message']];
  } catch (Throwable $error) {
    if (http_response_code() < 400) {
      http_response_code(422);
    }
    $status = ['type' => 'error', 'message' => $error instanceof RuntimeException ? $error->getMessage() : 'The operation could not finish. Check the archive and server configuration.'];
    if (!$error instanceof RuntimeException) {
      error_log('Unzipper: ' . $error->getMessage());
    }
  } finally {
    if (is_resource($lock)) {
      if ($hasLock) {
        flock($lock, LOCK_UN);
      }
      fclose($lock);
    }
  }
  $time = round(microtime(true) - $start, 2);
  if ($json) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => $status, 'result' => $result, 'seconds' => $time, 'archives' => $workspace->archives()], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
  }
}
if (session_status() === PHP_SESSION_ACTIVE) {
  session_write_close();
}
$archives = $workspace->archives();
$zipAvailable = class_exists('ZipArchive');
$rarAvailable = class_exists('RarArchive');
$gzipAvailable = function_exists('gzopen');
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Unzipper · Archive workspace</title>
  <style nonce="<?php echo escape($nonce); ?>">
    :root {
      color-scheme: light;
      --page: #f5f6fa;
      --card: #fff;
      --ink: #172033;
      --muted: #667085;
      --line: #e4e7ec;
      --primary: #4f46e5;
      --hover: #4338ca;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      background: var(--page);
      color: var(--ink);
      font: 15px/1.6 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    button,
    input,
    select {
      font: inherit;
    }

    button,
    input,
    select,
    a {
      -webkit-tap-highlight-color: transparent;
    }

    button {
      cursor: pointer;
    }

    button:disabled {
      cursor: not-allowed;
      opacity: .55;
    }

    :focus-visible {
      outline: 3px solid var(--primary);
      outline-offset: 4px;
    }

    [hidden] {
      display: none !important;
    }

    .symbols {
      display: none;
    }

    .icon {
      width: 22px;
      height: 22px;
      flex-shrink: 0;
      fill: none;
      stroke: currentColor;
      stroke-width: 1.8;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .workspace {
      max-width: 1124px;
      margin: auto;
      padding: 48px 32px 28px;
    }

    .header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 24px;
      margin-bottom: 36px;
    }

    .identity {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .mark {
      width: 44px;
      height: 44px;
      display: grid;
      place-items: center;
      border-radius: 12px;
      background: var(--primary);
      color: white;
    }

    h1,
    h2,
    h3,
    p {
      margin: 0;
    }

    h1 {
      font-size: 25px;
      line-height: 1.25;
      letter-spacing: -.8px;
      font-weight: 650;
    }

    .subtitle {
      color: var(--muted);
      font-size: 13px;
      margin-top: 3px;
    }

    .forms {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 24px;
    }

    .card {
      display: flex;
      flex-direction: column;
      min-width: 0;
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 16px;
      padding: 30px;
      box-shadow: 0 2px 4px #17203303;
    }

    .card-title {
      display: flex;
      gap: 12px;
      align-items: center;
      margin-bottom: 8px;
    }

    .card-title .icon {
      color: var(--primary);
    }

    h2 {
      font-size: 19px;
      letter-spacing: -.4px;
      font-weight: 650;
    }

    .description {
      color: var(--muted);
      font-size: 13px;
      margin-bottom: 24px;
    }

    form {
      display: flex;
      flex: 1;
      flex-direction: column;
      gap: 20px;
    }

    label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      margin-bottom: 7px;
    }

    .optional {
      font-weight: 400;
      color: var(--muted);
    }

    input[type=text],
    select {
      display: block;
      width: 100%;
      min-height: 45px;
      padding: 10px 12px;
      border: 1px solid #cfd4df;
      border-radius: 8px;
      background: #fafbfc;
      color: var(--ink);
      transition: border-color .15s, box-shadow .15s;
    }

    input::placeholder {
      color: #7b8497;
      opacity: 1;
    }

    input[type=text]:focus,
    select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px #4f46e518;
    }

    select:disabled {
      color: var(--muted);
    }

    .hint {
      color: var(--muted);
      font-size: 12px;
      line-height: 1.6;
      margin-top: 7px;
    }

    .action {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 9px;
      width: 100%;
      min-height: 45px;
      padding: 10px 20px;
      background: var(--primary);
      color: white;
      border: 0;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      transition: background .15s, transform .15s;
    }

    .action:hover:enabled {
      background: var(--hover);
      transform: translateY(-1px);
    }

    .action:active:enabled {
      transform: translateY(0);
    }

    .action .icon {
      width: 18px;
      height: 18px;
    }

    .button-wrap {
      margin-top: auto;
      padding-top: 6px;
    }

    .status {
      margin-top: 24px;
      background: white;
      border: 1px solid var(--line);
      border-radius: 14px;
      overflow: hidden;
    }

    .status-body {
      display: flex;
      align-items: flex-start;
      gap: 16px;
      padding: 24px;
    }

    .status-icon {
      display: grid;
      place-items: center;
      width: 38px;
      height: 38px;
      flex-shrink: 0;
      border-radius: 50%;
      color: #925c06;
      background: #fffaeb;
    }

    .status[data-state=success] .status-icon {
      background: #ecfdf3;
      color: #15723c;
    }

    .status[data-state=error] .status-icon {
      background: #fef3f2;
      color: #b42318;
    }

    .status[data-state=running] .status-icon {
      background: #eeedff;
      color: var(--primary);
    }

    .status-copy {
      min-width: 0;
      flex: 1;
    }

    h3 {
      font-size: 14px;
      font-weight: 650;
      margin-bottom: 3px;
    }

    #status-message {
      color: var(--muted);
      font-size: 13px;
      overflow-wrap: anywhere;
    }

    .elapsed {
      font: 12px/1.6 ui-monospace, SFMono-Regular, Consolas, monospace;
      color: var(--muted);
      white-space: nowrap;
    }

    .capabilities {
      display: flex;
      flex-wrap: wrap;
      gap: 7px;
      margin-top: 13px;
    }

    .chip {
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 2px 8px;
      font-size: 11px;
      color: var(--muted);
      background: #fafbfc;
    }

    .unavailable {
      background: #f9fafb;
    }

    .progress-area {
      padding: 0 24px 20px 78px;
    }

    .progress {
      height: 5px;
      background: #eeedff;
      border-radius: 3px;
      overflow: hidden;
    }

    .progress::after {
      content: "";
      display: block;
      height: 100%;
      width: 35%;
      border-radius: inherit;
      background: var(--primary);
      animation: slide 1.6s ease-in-out infinite;
    }

    .progress-caption {
      color: var(--muted);
      font-size: 12px;
      margin-top: 8px;
    }

    .result {
      display: flex;
      flex-wrap: wrap;
      gap: 12px 24px;
      margin-top: 12px;
      font-size: 12px;
      color: var(--muted);
    }

    .result strong {
      color: var(--ink);
      font-weight: 600;
    }

    .result-path {
      display: block;
      flex-basis: 100%;
      overflow-wrap: anywhere;
    }

    code {
      font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
      font-size: .95em;
    }

    .notes {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 28px;
      margin: 30px 0 40px;
    }

    .note {
      display: flex;
      gap: 12px;
    }

    .note>.icon {
      width: 19px;
      height: 19px;
      color: var(--muted);
      margin-top: 3px;
    }

    .note h3 {
      font-size: 12px;
      margin-bottom: 5px;
    }

    .note p {
      color: var(--muted);
      font-size: 12px;
      line-height: 1.7;
    }

    footer {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      gap: 10px;
      padding-top: 22px;
      border-top: 1px solid var(--line);
      color: var(--muted);
      font-size: 12px;
    }

    footer a {
      color: var(--muted);
      text-decoration: none;
    }

    footer a:hover {
      color: var(--primary);
      text-decoration: underline;
    }

    .spinner {
      display: none;
      width: 16px;
      height: 16px;
      border: 2px solid #ffffff70;
      border-top-color: white;
      border-radius: 50%;
    }

    form[aria-busy=true] .spinner {
      display: inline-block;
      animation: spin .8s linear infinite;
    }

    form[aria-busy=true] .button-icon {
      display: none;
    }

    @keyframes slide {
      from {
        transform: translateX(-110%);
      }

      to {
        transform: translateX(400%);
      }
    }

    @keyframes spin {
      to {
        transform: rotate(360deg);
      }
    }

    @media (max-width:720px) {
      .workspace {
        padding: 28px 20px;
      }

      .forms {
        grid-template-columns: 1fr;
        gap: 18px;
      }

      .notes {
        grid-template-columns: 1fr;
        gap: 20px;
      }

      .card {
        padding: 24px;
      }

      .header {
        margin-bottom: 26px;
      }

      .status-body {
        gap: 12px;
        padding: 20px;
        flex-wrap: wrap;
      }

      .elapsed {
        margin-left: 50px;
      }

      .progress-area {
        padding: 0 20px 20px;
      }
    }

    @media (max-width:420px) {
      .workspace {
        padding: 24px 14px;
      }

      .identity {
        gap: 10px;
      }

      h1 {
        font-size: 23px;
      }

      .subtitle {
        font-size: 11px;
      }

      .card {
        padding: 20px;
      }
    }

    @media (prefers-reduced-motion:reduce) {

      *,
      *::before,
      *::after {
        animation: none !important;
        transition: none !important;
      }

      .progress::after {
        width: 100%;
        opacity: .6;
      }
    }
  </style>
</head>

<body>
  <svg class="symbols" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <defs>
      <symbol id="archive" viewBox="0 0 24 24">
        <rect x="3" y="3" width="18" height="5" rx="1" />
        <path d="M5 8v12h14V8M10 12h4" />
      </symbol>
      <symbol id="extract" viewBox="0 0 24 24">
        <path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5" />
      </symbol>
      <symbol id="create" viewBox="0 0 24 24">
        <path d="M12 16V4m-5 5 5-5 5 5M4 16v5h16v-5" />
      </symbol>
      <symbol id="info" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="9" />
        <path d="M12 11v6m0-10v1" />
      </symbol>
      <symbol id="check" viewBox="0 0 24 24">
        <path d="m5 12 4 4L19 6" />
      </symbol>
      <symbol id="error" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="9" />
        <path d="m9 9 6 6m0-6-6 6" />
      </symbol>
      <symbol id="shield" viewBox="0 0 24 24">
        <path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6z" />
        <path d="m8 12 3 3 5-6" />
      </symbol>
      <symbol id="cleanup" viewBox="0 0 24 24">
        <path d="M4 6h16M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7m4-7v7" />
      </symbol>
    </defs>
  </svg>
  <main class="workspace">
    <header class="header">
      <div class="identity"><span class="mark"><svg class="icon" aria-hidden="true">
            <use href="#archive" />
          </svg></span>
        <div>
          <h1>Unzipper</h1>
          <p class="subtitle">Your server archive workspace</p>
        </div>
      </div>
    </header>
    <div class="forms">
      <section class="card" aria-labelledby="extract-title">
        <div class="card-title"><svg class="icon" aria-hidden="true">
            <use href="#extract" />
          </svg>
          <h2 id="extract-title">Extract archive</h2>
        </div>
        <p class="description">Unpack an archive into your workspace.</p>
        <form method="post" action="" data-operation="extract">
          <input type="hidden" name="csrf" value="<?php echo escape($csrf); ?>">
          <input type="hidden" name="action" value="extract">
          <div>
            <label for="zipfile">Source archive</label>
            <select id="zipfile" name="zipfile" required aria-describedby="archive-info" <?php echo $archives === [] ? 'disabled' : ''; ?>>
              <?php if ($archives === []): ?>
                <option value="">No archives found</option><?php endif; ?>
              <?php foreach ($archives as $name => $size): ?>
                <?php $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $supported = $extension === 'zip' ? $zipAvailable : ($extension === 'rar' ? $rarAvailable : $gzipAvailable); ?>
                <option value="<?php echo escape($name); ?>" data-bytes="<?php echo $size; ?>" <?php echo $name === $archiveValue ? 'selected' : ''; ?>   <?php echo !$supported ? 'disabled' : ''; ?>>
                  <?php echo escape($name) . ' · ' . formatBytes($size) . (!$supported ? ' · unavailable' : ''); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="hint" id="archive-info">
              <?php echo $archives === [] ? 'Place a ZIP, RAR, GZIP or TAR.GZ archive beside this script, then reload.' : count($archives) . ' archive(s) found in this workspace. Unavailable formats are disabled.'; ?>
            </p>
          </div>
          <div>
            <label for="extpath">Extraction path <span class="optional">(optional)</span></label>
            <input id="extpath" name="extpath" type="text" value="<?php echo escape($extractValue); ?>"
              placeholder="e.g. site-restore" maxlength="2048" aria-describedby="extpath-hint" autocomplete="off"
              spellcheck="false">
            <p id="extpath-hint" class="hint">Use a relative folder. Leave empty for the current directory. Existing
              files are never overwritten.</p>
          </div>
          <div class="button-wrap"><button class="action" type="submit" <?php echo $archives === [] || !($zipAvailable || $rarAvailable || $gzipAvailable) ? 'disabled' : ''; ?>><svg class="icon button-icon" aria-hidden="true">
                <use href="#extract" />
              </svg><span class="spinner" aria-hidden="true"></span><span class="button-label">Extract
                archive</span></button></div>
        </form>
      </section>
      <section class="card" aria-labelledby="create-title">
        <div class="card-title"><svg class="icon" aria-hidden="true">
            <use href="#create" />
          </svg>
          <h2 id="create-title">Create archive</h2>
        </div>
        <p class="description">Bundle a directory into a new ZIP file.</p>
        <form method="post" action="" data-operation="create">
          <input type="hidden" name="csrf" value="<?php echo escape($csrf); ?>">
          <input type="hidden" name="action" value="create">
          <div>
            <label for="zippath">Path to zip <span class="optional">(optional)</span></label>
            <input id="zippath" name="zippath" type="text" value="<?php echo escape($sourceValue); ?>"
              placeholder="e.g. uploads/images" maxlength="2048" aria-describedby="zippath-hint" autocomplete="off"
              spellcheck="false">
            <p id="zippath-hint" class="hint">Leave empty for the current directory. Dotfiles, links, existing archives
              and this utility are excluded.</p>
          </div>
          <p class="hint">A uniquely named ZIP will be saved beside this script. Source files stay in place.</p>
          <?php if (!$zipAvailable): ?>
            <p class="hint">Creating archives requires the PHP zip extension.</p><?php endif; ?>
          <div class="button-wrap"><button class="action" type="submit" <?php echo !$zipAvailable ? 'disabled' : ''; ?>>
              <svg class="icon button-icon" aria-hidden="true">
                <use href="#create" />
              </svg>
              <span class="spinner" aria-hidden="true"></span>
              <span class="button-label">Create ZIP</span>
            </button>
          </div>
        </form>
      </section>
    </div>
    <section class="status" id="status-panel" data-state="<?php echo escape($status['type']); ?>"
      aria-label="Operation status" tabindex="-1">
      <div class="status-body">
        <span class="status-icon"><svg class="icon" aria-hidden="true">
            <use id="status-symbol"
              href="#<?php echo $status['type'] === 'success' ? 'check' : ($status['type'] === 'error' ? 'error' : 'info'); ?>" />
          </svg></span>
        <div class="status-copy">
          <div role="status" aria-live="polite" aria-atomic="true">
            <h3 id="status-title">
              <?php echo $status['type'] === 'success' ? 'Operation complete' : ($status['type'] === 'error' ? 'Could not complete operation' : 'Ready when you are'); ?>
            </h3>
            <p id="status-message"><?php echo escape($status['message']); ?></p>
          </div>
          <div class="result" id="result-details" <?php echo $result === null ? 'hidden' : ''; ?>>
            <span><strong id="result-files"><?php echo $result['files'] ?? 0; ?></strong> files</span><span><strong
                id="result-bytes"><?php echo formatBytes($result['bytes'] ?? 0); ?></strong> expanded data</span>
            <span class="result-path">Saved to <code
                id="result-path"><?php echo escape($result['path'] ?? ''); ?></code></span>
            <span id="result-skipped" <?php echo empty($result['skipped']) ? 'hidden' : ''; ?>><?php echo (int) ($result['skipped'] ?? 0); ?> excluded item(s)</span>
          </div>
          <div class="capabilities" aria-label="Server archive support">
            <?php foreach (['ZIP' => $zipAvailable, 'GZIP' => $gzipAvailable, 'TAR.GZ' => $gzipAvailable, 'RAR' => $rarAvailable] as $format => $available): ?>
              <span
                class="chip <?php echo !$available ? 'unavailable' : ''; ?>"><?php echo $format . ($available ? ' · available' : ' · unavailable'); ?></span>
            <?php endforeach; ?>
          </div>
        </div>
        <output class="elapsed" id="elapsed"
          aria-live="off"><?php echo $method === 'POST' ? $time . 's elapsed' : 'Idle'; ?></output>
      </div>
      <div class="progress-area" id="progress-area" hidden>
        <div class="progress" role="progressbar" aria-label="Archive operation in progress"></div>
        <p class="progress-caption">Working on the server. Keep this page open.</p>
      </div>
    </section>
    <div class="notes">
      <div class="note"><svg class="icon" aria-hidden="true">
          <use href="#shield" />
        </svg>
        <div>
          <h3>Within your workspace</h3>
          <p>Relative paths only. Links and traversal are blocked. Each operation is limited to 10,000 entries and 512
            MB.</p>
        </div>
      </div>
      <div class="note"><svg class="icon" aria-hidden="true">
          <use href="#info" />
        </svg>
        <div>
          <h3>Use trusted archives</h3>
          <p>Extracted scripts can run on your server. Review the source and use a new folder to avoid file conflicts.
          </p>
        </div>
      </div>
      <div class="note"><svg class="icon" aria-hidden="true">
          <use href="#cleanup" />
        </svg>
        <div>
          <h3>Clean up after use</h3>
          <p>Remove this utility and archive files when finished. Archive files may be publicly accessible on your
            server.</p>
        </div>
      </div>
    </div>
    <noscript>
      <p class="hint">JavaScript is disabled. Forms still work; the result will appear after the page reloads.</p>
    </noscript>
    <footer><span>Unzipper v<?php echo VERSION; ?></span><span>Local files · No external assets · <a
          href="https://github.com/ndeet/unzipper" rel="noreferrer">Project &amp; documentation</a></span></footer>
  </main>
  <script nonce="<?php echo escape($nonce); ?>">
    (() => {
      'use strict';
      if (!window.fetch || !window.FormData) return;
      const forms = [...document.querySelectorAll('form[data-operation]')];
      const panel = document.getElementById('status-panel');
      const title = document.getElementById('status-title');
      const message = document.getElementById('status-message');
      const progress = document.getElementById('progress-area');
      const elapsed = document.getElementById('elapsed');
      const details = document.getElementById('result-details');
      const select = document.getElementById('zipfile');
      const capabilities = <?php echo json_encode(['zip' => $zipAvailable, 'rar' => $rarAvailable, 'gz' => $gzipAvailable, 'tgz' => $gzipAvailable]); ?>;
      const extractButton = forms[0].querySelector('button');
      let busy = false;
      let uncertain = false;
      const bytes = value => value < 1048576 ? (value / 1024).toFixed(1) + ' KB' : (value / 1048576).toFixed(1) + ' MB';
      const updateExtractButton = () => { extractButton.disabled = busy || uncertain || !select.value || select.selectedOptions[0]?.disabled; };
      select.addEventListener('change', updateExtractButton);
      updateExtractButton();

      function status(state, heading, text) {
        panel.dataset.state = state;
        title.textContent = heading;
        message.textContent = text;
        document.getElementById('status-symbol').setAttribute('href', '#' + (state === 'success' ? 'check' : state === 'error' ? 'error' : 'info'));
      }

      function updateArchives(archives, selected) {
        select.replaceChildren();
        for (const [name, size] of Object.entries(archives)) {
          const option = document.createElement('option');
          const supported = capabilities[name.split('.').pop().toLowerCase()];
          option.value = name;
          option.textContent = name + ' · ' + bytes(size) + (supported ? '' : ' · unavailable');
          option.disabled = !supported;
          select.append(option);
        }
        select.disabled = select.options.length === 0;
        if (select.disabled) {
          const option = document.createElement('option');
          option.value = '';
          option.textContent = 'No archives found';
          select.append(option);
        } else if ([...select.options].some(option => option.value === selected && !option.disabled)) {
          select.value = selected;
        } else {
          const first = [...select.options].find(option => !option.disabled);
          select.value = first?.value || '';
        }
        document.getElementById('archive-info').textContent = Object.keys(archives).length + ' archive(s) found in this workspace. Unavailable formats are disabled.';
      }

      for (const form of forms) {
        form.addEventListener('submit', async event => {
          event.preventDefault();
          if (busy || uncertain || !form.reportValidity()) return;
          const data = new FormData(form);
          const operation = form.dataset.operation;
          const previousSelection = select.value;
          const controls = [...document.querySelectorAll('form input, form select, form button')].map(element => [element, element.disabled]);
          const label = form.querySelector('.button-label');
          const previousLabel = label.textContent;
          busy = true;
          for (const [element] of controls) element.disabled = true;
          form.setAttribute('aria-busy', 'true');
          label.textContent = operation === 'extract' ? 'Extracting…' : 'Creating ZIP…';
          details.hidden = true;
          progress.hidden = false;
          status('running', operation === 'extract' ? 'Extracting archive…' : 'Creating ZIP…', 'Checking paths and processing files. Larger archives may take a little longer.');
          const started = performance.now();
          elapsed.textContent = '0.0s elapsed';
          const timer = setInterval(() => { elapsed.textContent = ((performance.now() - started) / 1000).toFixed(1) + 's elapsed'; }, 250);
          let responseData;
          try {
            const response = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Unexpected server response');
            responseData = await response.json();
            if (!responseData.status || !['success', 'error'].includes(responseData.status.type)) throw new Error('Unexpected server response');
            status(responseData.status.type, responseData.status.type === 'success' ? 'Operation complete' : 'Could not complete operation', responseData.status.message);
            if (responseData.result) {
              const result = responseData.result;
              document.getElementById('result-files').textContent = result.files;
              document.getElementById('result-bytes').textContent = bytes(result.bytes);
              document.getElementById('result-path').textContent = result.path;
              const skipped = document.getElementById('result-skipped');
              skipped.hidden = !result.skipped;
              skipped.textContent = (result.skipped || 0) + ' excluded item(s)';
              details.hidden = false;
            }
            elapsed.textContent = responseData.seconds.toFixed(2) + 's elapsed';
          } catch (error) {
            uncertain = true;
            status('error', 'Connection interrupted', 'The server may still be working. Reload this page and check the destination before starting another operation.');
            elapsed.textContent = ((performance.now() - started) / 1000).toFixed(1) + 's elapsed';
          } finally {
            clearInterval(timer);
            busy = false;
            form.removeAttribute('aria-busy');
            label.textContent = previousLabel;
            progress.hidden = true;
            for (const [element, disabled] of controls) element.disabled = uncertain || disabled;
            if (responseData?.archives && !uncertain) updateArchives(responseData.archives, operation === 'create' && responseData.result ? responseData.result.path : previousSelection);
            updateExtractButton();
            panel.focus({ preventScroll: true });
          }
        });
      }
    })();
  </script>
</body>

</html>