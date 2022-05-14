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
     * @var int
     */
    protected $compressedArchivesToKeep;

    /**
     * @var int
     */
    protected $maximumCompressSize;

    /**
     * @var int
     */
    protected $maximumArchivedFiles;

    /**
     * @var string
     */
    protected $compressInterval = 'P1D';

    /**
     * @var string
     */
    protected $archiveName = 'Exceptions.Y-m-d.tar.gz';

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
        parent::createWithOptions($options);

        $storagePath    = $options['storagePath'] ?? (FLOW_PATH_DATA . 'Logs/Exceptions');
        $maximumDirSize = $options['maximumDirSize'] ?? 0;
        $logFilesToKeep = $options['logFilesToKeep'] ?? null;
        $glob           = $options['glob'] ?? '*.txt';

        $compress                 = $options['compress'] ?? false;
        $compressedArchivesToKeep = $options['compressedArchivesToKeep'] ?? true;
        $maximumCompressSize      = $options['maximumCompressSize'] ?? null;
        $maximumArchivedFiles     = $options['maximumArchivedFiles'] ?? null;
        $compressInterval         = $options['compressInterval'] ?? true;
        $archiveName              = $options['archiveName'] ?? true;
        $archiveGlob              = $options['archiveGlob'] ?? true;

        return new static(
            $storagePath,
            $glob,
            $maximumDirSize,
            $logFilesToKeep,
            $compress,
            $compressedArchivesToKeep,
            $maximumCompressSize,
            $maximumArchivedFiles,
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
     * @param int|null $compressedArchivesToKeep
     * @param int|null $maximumCompressSize
     * @param int|null $maximumArchivedFiles
     * @param string $compressInterval
     * @param string $archiveName
     * @param string $archiveGlob
     */
    public function __construct(
        string $storagePath,
        string $glob        = '*.txt',
        int $maximumDirSize = 0,
        int $logFilesToKeep = null,
        bool $compress      = false,
        int $compressedArchivesToKeep = null,
        int $maximumCompressSize      = null,
        int $maximumArchivedFiles     = null,
        string $compressInterval      = 'P1D',
        string $archiveName = 'Exceptions.Y-m-d.tar.gz',
        string $archiveGlob = 'Exceptions.*.tar.gz'
    ) {
        parent::__construct($storagePath);

        $this->glob           = $glob;
        $this->maximumDirSize = $maximumDirSize;
        $this->logFilesToKeep = $logFilesToKeep;

        $this->compress                 = $compress;
        $this->compressedArchivesToKeep = $compressedArchivesToKeep;
        $this->maximumCompressSize      = $maximumCompressSize;
        $this->maximumArchivedFiles     = $maximumArchivedFiles;
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

        if ($this->compress === false) {
            // Case 1: Just delete files if there are too many
            if (0 < $this->logFilesToKeep) {
                if($this->logFilesToKeep < count($files)) {
                    $filesToDelete = \array_slice($files, $this->logFilesToKeep);

                    foreach ($filesToDelete as $file) {
                        \unlink($file);
                    }
                }
            }
            // Case 2: Delete Files if size of directory is too big
            if (0 < $this->maximumDirSize) {
                $dirSize = \array_sum(\array_map(function ($file) {
                    return \filesize($file);
                }, $files));
                while (0 < $dirSize && $this->maximumDirSize < $dirSize) {
                    $file = \array_shift($files);
                    $dirSize -= \filesize($file);
                    \unlink($file);
                }
            }
        } else {
            // Case 3: Archive files if compress is enabled
            $dateInterval = new \DateInterval($this->compressInterval);
            // TODO:
            //   Split $files depending on logFilesToKeep or maximumDirSize into a `keep` and `compress` chunk
            //   Determine the next archive file using findArchiveName
            //   Use PharData to move the files by their modified date from the compress chunk into the archive
            //   Remove older archives if there are more archives than compressedArchivesToKeep
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
