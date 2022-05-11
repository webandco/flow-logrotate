<?php
declare(strict_types=1);

namespace Webandco\Logrotate\Log\Backend;

/*
 * This file is part of the Neos.Flow package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Cesargb\Log\Rotation;
use Neos\Flow\Log\Backend\FileBackend;
use Neos\Flow\Log\Exception\CouldNotOpenResourceException;
use Neos\Utility\Exception\FilesException;

/**
 * A log backend which writes log entries into a file
 *
 * @api
 */
class RotateBackend extends FileBackend
{
    /**
     * @var boolean
     */
    protected $compress = true;

    /**
     * @var boolean
     */
    protected $truncate = false;

    /**
     * If enabled, compress the file after rotated
     *
     * @param boolean $flag
     * @return void
     * @api
     */
    public function setCompress(bool $flag): void
    {
        $this->compress = ($flag === true);
    }

    /**
     * If enabled, log files are truncated log file in place after copy instead of moving the file
     *
     * @param boolean $flag
     * @return void
     * @api
     */
    public function setTruncate(bool $flag): void
    {
        $this->truncate = ($flag === true);
    }

    /**
     * Carries out all actions necessary to prepare the logging backend, such as opening
     * the log file or opening a database connection.
     *
     * @return void
     * @throws CouldNotOpenResourceException
     * @throws FilesException
     * @api
     */
    public function open(): void
    {
        if(\file_exists($this->logFileUrl)) {
            $rotation = new Rotation([
                'files' => $this->logFilesToKeep,
                'compress' => $this->compress,
                'min-size' => $this->maximumLogFileSize,
                'truncate' => $this->truncate,
                //'then' => function ($filename) {},
                //'catch' => function (RotationFailed $exception) {},
                //'finally' => function ($message, $filename) {},
            ]);
            $rotation->rotate($this->logFileUrl);
        }

        parent::open();
    }
}
