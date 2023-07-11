<?php
namespace Webandco\Logrotate\Log\ThrowableStorage;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Log\PsrLoggerFactoryInterface;
use Neos\Flow\Log\ThrowableStorage\FileStorage;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;

/**
 * Stores detailed information about throwables into files.
 *
 * @Flow\Proxy(false)
 * @Flow\Autowiring(false)
 */
class CleanupStorage extends FileStorage
{
    /**
     * @var int
     */
    protected $maximumDirSize;

    /**
     * @var int
     */
    protected $logFilesToKeep;

    /**
     * @var string
     */
    protected $glob;

    /**
     * @var bool
     */
    protected $compress;

    /**
     * @var string
     */
    protected $compressionAlgorithm = 'GZ';

    /**
     * @var int
     */
    protected $compressedArchivesToKeep;

    /**
     * @var string
     */
    protected $compressInterval = 'P1D';

    /**
     * @var string
     */
    protected $archiveName = ['prefix' => 'Exceptions.', 'dateTime' => 'Y-m-d', 'postfix' => '.tar.gz'];

    /**
     * @var string
     */
    protected $archiveGlob = 'Exceptions.*.tar.gz';

    /**
     * Factory method to get an instance.
     *
     * @param array $options
     * @return ThrowableStorageInterface
     */
    public static function createWithOptions(array $options): ThrowableStorageInterface
    {
        $storagePath    = $options['storagePath'] ?? (FLOW_PATH_DATA . 'Logs/Exceptions');
        $maximumDirSize = $options['maximumDirSize'] ?? 0;
        $logFilesToKeep = $options['logFilesToKeep'] ?? null;
        $glob           = $options['glob'] ?? '*.txt';

        $compress                 = $options['compress'] ?? false;
        $compressionAlgorithm     = $options['compressionAlgorithm'] ?? 'gz';
        $compressedArchivesToKeep = $options['compressedArchivesToKeep'] ?? true;
        $compressInterval         = $options['compressInterval'] ?? true;
        $archiveName              = $options['archiveName'] ?? true;
        $archiveGlob              = $options['archiveGlob'] ?? true;

        return new static(
            $storagePath,
            $glob,
            $maximumDirSize,
            $logFilesToKeep,
            $compress,
            $compressionAlgorithm,
            $compressedArchivesToKeep,
            $compressInterval,
            $archiveName,
            $archiveGlob
        );
    }

    /**
     * CleanupStorage constructor.
     * @param string $storagePath
     * @param string $glob
     * @param int $maximumDirSize
     * @param int|null $logFilesToKeep
     * @param bool $compress
     * @param string $compressionAlgorithm
     * @param int|null $compressedArchivesToKeep
     * @param string $compressInterval
     * @param array $archiveName
     * @param string $archiveGlob
     */
    public function __construct(
        string $storagePath,
        string $glob        = '*.txt',
        int $maximumDirSize = 0,
        int $logFilesToKeep = null,
        bool $compress      = false,
        string $compressionAlgorithm = 'gz',
        int $compressedArchivesToKeep = null,
        string $compressInterval      = 'P1D',
        array $archiveName = ['prefix' => 'Exceptions.', 'dateTime' => 'Y-m-d', 'postfix' => '.tar.gz'],
        string $archiveGlob = 'Exceptions.*.tar.gz'
    ) {
        parent::__construct($storagePath);

        $this->glob           = $glob;
        $this->maximumDirSize = $maximumDirSize;
        $this->logFilesToKeep = $logFilesToKeep;

        $this->compress                 = $compress;
        $this->compressionAlgorithm     = $compressionAlgorithm;
        $this->compressedArchivesToKeep = $compressedArchivesToKeep;
        $this->compressInterval         = $compressInterval;
        $this->archiveName              = $archiveName;
        $this->archiveGlob              = $archiveGlob;
    }

    /**
     * @param \Throwable $throwable
     * @param array $additionalData
     * @return string Informational message about the stored throwable
     */
    public function logThrowable(\Throwable $throwable, array $additionalData = [])
    {
        $message = parent::logThrowable($throwable, $additionalData);
        try {
            $this->processStoragePath();
        } catch (\Throwable $t) {
            Bootstrap::$staticObjectManager->get(PsrLoggerFactoryInterface::class)->get('systemLogger')->error(
                sprintf('Exception cleanup failed: %s', (string)$t),
                LogEnvironment::fromMethodName(__METHOD__)
            );
        }

        return $message;
    }

    /**
     * @throws \Exception
     */
    public function processStoragePath()
    {
        $files = $this->getFilesSortedByModifiedDate();

        $filesToHandleCase1 = [];
        $filesToKeepCase1 = [];
        // Check for max amount of files to keep and get rest
        if (0 < $this->logFilesToKeep) {
            if($this->logFilesToKeep < count($files)) {
                $filesToHandleCase1 = \array_slice($files, 0, -$this->logFilesToKeep);
                $filesToKeepCase1 = \array_slice($files, count($files) - $this->logFilesToKeep);
            }
        }

        $filesToHandleCase2 = [];
        $filesToKeepCase2 = [];
        // Also check for max size of directory and remove oldest modified file until the size of directory is small enough
        if (0 < $this->maximumDirSize) {
            $dirSize = \array_sum(\array_map(function ($file) {
                return \filesize($file);
            }, $files));
            while (0 < $dirSize && $this->maximumDirSize < $dirSize) {
                $file = \array_shift($files);
                $dirSize -= \filesize($file);
                $filesToHandleCase2[] = $file;
            }

            $filesToKeepCase2 = $files;
        }

        // Get the one of the two cases above which has more files to delete / compress
        if (count($filesToHandleCase2) > count($filesToHandleCase1)) {
            $filesToHandle = $filesToHandleCase2;
            $filesToKeep = $filesToKeepCase2;
        } else {
            $filesToHandle = $filesToHandleCase1;
            $filesToKeep = $filesToKeepCase1;
        }

        // Start Compressing files and creating archives
        if ($this->compress == true) {
            // Create array of files to add for each archive
            $dateInterval = new \DateInterval($this->compressInterval);

            // we ignore the current archive and archives for $filesToKeep
            // because PharData is buggy and causes an Exception
            // if a previously generated .tar.gz shall be opened and extended with new files:
            // "Exceptions.2023-03-09.tar.gz" is a corrupted tar file (checksum mismatch of file ...
            // there might be a related bug report: https://bugs.php.net/bug.php?id=75102
            // to avoid this error, we keep exception files in the current interval
            // and only past exceptions are compressed into a tar.gz
            $archiveNameToIgnore = [];
            $archiveNameToIgnore[] = $this->findArchiveName(time(), $dateInterval, $this->archiveName['dateTime']);
            foreach ($filesToKeep as $fileToKeep) {
                $archiveNameToIgnore[] = $this->findArchiveName(\filemtime($fileToKeep), $dateInterval, $this->archiveName['dateTime']);
            }

            $archiveToFiles = [];
            foreach ($filesToHandle as $idx => $file) {
                $name = $this->findArchiveName(\filemtime($file), $dateInterval, $this->archiveName['dateTime']);
                if (\in_array($name, $archiveNameToIgnore)) {
                    // prevent delete exception files which would be compressed into current .tar.gz
                    unset($filesToHandle[$idx]);
                    continue;
                }

                $archiveToFiles[$name][] = $file;
            }
            $filesToHandle = \array_values($filesToHandle);

            if (count($archiveToFiles)) {
                // Create archive for each mapping, add the new files to it
                foreach ($archiveToFiles as $archiveCategory => $files) {
                    $archivePath = $this->storagePath . '/' . $this->archiveName['prefix'] . $archiveCategory;
                    $archivePathWithEnding = $archivePath . $this->archiveName['postfix'];
                    $archiveExists = \file_exists($archivePathWithEnding);

                    $phar = new \PharData($archiveExists ? $archivePathWithEnding : $archivePath);
                    foreach ($files as $file) {
                        $phar->addFile($file, basename($file));
                    }
                    // Create compression archive if not yet created
                    if (!$archiveExists) {
                        switch (strtoupper($this->compressionAlgorithm)) {
                            case 'GZ':
                                $alg = \Phar::GZ;
                                break;
                            case 'BZ2':
                                $alg = \Phar::BZ2;
                                break;
                            default:
                                $alg = \Phar::NONE;
                        }
                        // The extension is so weird, because there is a major bug in the compress() method: https://www.php.net/manual/en/phardata.compress.php
                        $phar->compress(
                            $alg,
                            substr($archivePath, strpos($archivePath, '.') + 1) . $this->archiveName['postfix']
                        );
                        // Delete old archive, since new PharData() automatically creates an archive, and compress then creates one too
                        unlink($archivePath);
                    }
                }

                // Delete too old archives
                $archives = $this->getArchivesSortedByModifiedDate();
                $archivesToDelete = \array_slice($archives, 0, -$this->compressedArchivesToKeep);
                $this->deleteFiles($archivesToDelete);
            }
        }

//        // Delete handled files
        $this->deleteFiles($filesToHandle);
    }

    protected function deleteFiles($files) {
        foreach ($files as $file) {
            unlink($file);
        }
    }

    /**
     * @return array|false
     */
    protected function getFilesSortedByModifiedDate()
    {
        $files = \glob($this->storagePath.DIRECTORY_SEPARATOR.$this->glob);

        \usort($files, function (string $f1, string $f2) {
            return \filemtime($f1) <=> filemtime($f2);
        });

        return $files;
    }

    /**
     * @return array|false
     */
    protected function getArchivesSortedByModifiedDate()
    {
        $archives = \glob($this->storagePath.DIRECTORY_SEPARATOR.$this->archiveGlob);

        \usort($archives, function (string $f1, string $f2) {
            return \filemtime($f1) <=> filemtime($f2);
        });

        return $archives;
    }

    /**
     * Given a timestamp, returns the formated date time string the timestamp fits into given an interval
     *
     * @param int $lastModifiedTimestamp
     * @param \DateInterval $interval
     * @param string $archiveNameDateTime
     * @return string
     * @throws \Exception
     */
    protected function findArchiveName(
        int $lastModifiedTimestamp,
        \DateInterval $interval,
        string $archiveNameDateTime
    ) {
        $start = new \DateTimeImmutable();
        $start = $start->setTimestamp($lastModifiedTimestamp);
        $startYear = (int)$start->format('Y');

        // set to begin of this year
        $start = $start->setTime(0, 0, 0, 0);
        // check if dateinterval has a year, month or day value set, to reduce the number of for loops below
        if (0 < \max($interval->y, $interval->m, $interval->d)) {
            $start = $start->setDate($startYear, 1, 1);
        }

        $intervalInSeconds = $start->add($interval)->getTimestamp() - $start->getTimestamp();

        $numberOfIntervalsUntilLastModifiedTimestamp = \floor(($lastModifiedTimestamp - $start->getTimestamp()) / $intervalInSeconds);

        // compute interval begin DateTime in a loop works correctly
        $lastModifiedIntervalBegin = \DateTime::createFromImmutable($start);
        for($i=0;$i<$numberOfIntervalsUntilLastModifiedTimestamp;$i++){
            $lastModifiedIntervalBegin->add($interval);
        }
        // calculate the interval directly can be incorrect because of daylight saving
        //$lastModifiedIntervalBegin = (new \DateTime())->setTimestamp($start->getTimestamp() + $numberOfIntervalsUntilLastModifiedTimestamp * $intervalInSeconds);

        return $lastModifiedIntervalBegin->format($archiveNameDateTime);
    }
}
