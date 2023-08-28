# webandco/flow-logrotate

Rotate log and exception files

## Installation

```
composer require webandco/flow-logrotate
```

## Configuration

### Logfiles

For logfile rotation we use [cesargb/php-log-rotation](https://github.com/cesargb/php-log-rotation).  

There are quite a few default logfiles like
* systemLogger
* securityLogger
* sqlLogger
* i18nLogger

For example to rotate the systemLogger you can set the config like this
```
Neos:
  Flow:
    log:
      psr3:
        Neos\Flow\Log\PsrLoggerFactory:
          systemLogger:
            default:
              class: Webandco\Logrotate\Log\Backend\RotateBackend
              options:
                compress: true
                truncate: false
```

Replace `Neos.Flow.psr3.Neos\Flow\Log\PsrLoggerFactory.systemLogger`
by whatever logger you want to rotate, 
e.g. `Neos.Flow.psr3.Neos\Flow\Log\PsrLoggerFactory.sqlLogger`.

## Exceptions

Exception log rotation is enabled by
```
Neos:
  Flow:
    log:
      throwables:
        storageClass: Webandco\Logrotate\Log\ThrowableStorage\CleanupStorage
        optionsByImplementation:
          Webandco\Logrotate\Log\ThrowableStorage\CleanupStorage:
            storagePath: '%FLOW_PATH_DATA%Logs/Exceptions'
            maximumDirSize: 10485760
            logFilesToKeep: 3
            compress: true
            compressedArchivesToKeep: 10
            compressInterval: 'P1D'
            compressionAlgorithm: 'gz'
            archiveName:
              prefix: 'Exceptions.'
              dateTime: 'Y-m-d'
              postfix: '.tar.gz'
            # Pattern for archives of interest
            archiveGlob: 'Exceptions.*.tar.gz'
            # Pattern for files of interest
            glob: '*.txt'
```

Since every exception is written in a separate exception txt file those files
are not rotated, but removed automatically.
If compression is enabled with `compress: true` then, if an exception is logged,
all exception files are compressed into an archive, except for those exception files,
which would be considered for the current (aka `now()`) compression interval.    
This means, if you have `compressInterval: 'P1D'` and an exception occures, it is left as is.
If tomorrow a second exception occures, the first exception from yesterday is compressed,
and the current exception is left as is in the exception txt.

## Testing

To test the settings you can cause log messages by a hidden cli command.  
To test an exception use
```
./flow rotate:log --exception --howmany=2
```
To test a log message use
```
./flow rotate:log --howmany=2 --level=error --words=5 --logger=systemLogger
```
Possible options for `logger` are those found in Settings `Neos.Flow.log.psr3.Neos\Flow\Log\PsrLoggerFactory`:
* systemLogger
* securityLogger
* sqlLogger
* i18nLogger
and possible custom logger configurations.
