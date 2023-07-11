<?php
namespace Webandco\Logrotate\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Core\Booting\Exception\SubProcessException;
use Neos\Flow\Core\Booting\Scripts;
use Neos\Flow\Log\PsrLoggerFactoryInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;

/**
 * Provides test commands to generate log entries
 * The commands are flagged with Flow\Internal to not show them in the output of ./flow help
 *
 * @Flow\Scope("singleton")
 */
class RotateCommandController extends CommandController
{
    /**
     * @Flow\InjectConfiguration(package="Neos.Flow")
     * @var array
     */
    protected $flowSettings;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * Write a number of random log messages
     *
     * @Flow\Internal
     * @param integer $address
     * @return void
     */
    public function logCommand(bool $exception = false, int $howmany=1, int $words=4, string $logger='systemLogger', int $level=1)
    {
        for($i=0;$i<$howmany;$i++) {
            $args = [];
            if ($exception) {
                $args['exception'] = true;

                try {
                    Scripts::executeCommand('rotate:internal', $this->flowSettings, true, $args);
                }
                // ignore subprocessexception
                catch (SubProcessException $spe) {}
            } else {
                $args['message'] = $this->lorem(1, $words, false);
                $args['logger'] = $logger;
                $args['level'] = $level;

                Scripts::executeCommand('rotate:internal', $this->flowSettings, true, $args);
            }
        }
    }

    /**
     * Write a number of random log messages
     *
     * @Flow\Internal
     * @param integer $address
     * @return void
     */
    public function internalCommand(bool $exception=false, string $message=null, string $logger='systemLogger', int $level=1)
    {
        if($exception){
            throw new \Exception($this->lorem(), 1652176822);
        }
        else{
            $logger = $this->objectManager->get(PsrLoggerFactoryInterface::class)->get($logger);
            $logger->log($level, $message, LogEnvironment::fromMethodName(__METHOD__));
            $this->logCommand();
        }
    }

    /**
     * From https://stackoverflow.com/a/58089529/73594
     *
     * @param int $count
     * @param int $max
     * @param bool $standard
     * @return string
     */
    protected function lorem($count = 1, $max = 20, $standard = true) {
        $output = '';

        if ($standard) {
            $output = 'Lorem ipsum dolor sit amet, consectetur adipisicing elit, ' .
                'sed do eiusmod tempor incididunt ut labore et dolore magna ' .
                'aliqua.';
        }

        $pool = explode(
            ' ',
            'a ab ad accusamus adipisci alias aliquam amet animi aperiam ' .
            'architecto asperiores aspernatur assumenda at atque aut beatae ' .
            'blanditiis cillum commodi consequatur corporis corrupti culpa ' .
            'cum cupiditate debitis delectus deleniti deserunt dicta ' .
            'dignissimos distinctio dolor ducimus duis ea eaque earum eius ' .
            'eligendi enim eos error esse est eum eveniet ex excepteur ' .
            'exercitationem expedita explicabo facere facilis fugiat harum ' .
            'hic id illum impedit in incidunt ipsa iste itaque iure iusto ' .
            'laborum laudantium libero magnam maiores maxime minim minus ' .
            'modi molestiae mollitia nam natus necessitatibus nemo neque ' .
            'nesciunt nihil nisi nobis non nostrum nulla numquam occaecati ' .
            'odio officia omnis optio pariatur perferendis perspiciatis ' .
            'placeat porro possimus praesentium proident quae quia quibus ' .
            'quo ratione recusandae reiciendis rem repellat reprehenderit ' .
            'repudiandae rerum saepe sapiente sequi similique sint soluta ' .
            'suscipit tempora tenetur totam ut ullam unde vel veniam vero ' .
            'vitae voluptas'
        );

        $max = ($max <= 3) ? 4 : $max;

        for ($i = 0, $add = ($count - (int) $standard); $i < $add; $i++) {
            shuffle($pool);
            $words = array_slice($pool, 0, mt_rand(3, $max));
            $output .= ((! $standard && $i === 0) ? '' : ' ') . ucfirst(implode(' ', $words)) . '.';
        }

        return $output;
    }
}
