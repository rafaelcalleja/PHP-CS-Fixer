<?php

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Symfony\CS;

use GitWrapper\GitWorkingCopy;
use GitWrapper\GitWrapper;
use PHPGit\Git;
use SebastianBergmann\Diff\Differ;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Finder\Finder as SymfonyFinder;
use Symfony\Component\Finder\SplFileInfo as SymfonySplFileInfo;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\CS\Tokenizer\Tokens;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 */
class Commiter
{
    /**
     * Differ instance.
     *
     * @var Differ
     */
    protected $diff;

    /**
     * EventDispatcher instance.
     *
     * @var EventDispatcher|null
     */
    protected $eventDispatcher;

    /**
     * @var GitWorkingCopy
     */
    private $git;

    /**
     * @var bool
     */
    private $force;
    
    /**
     * @var bool
     */
    private $dryRun;

    public function __construct(EventDispatcher $eventDispatcher, GitWorkingCopy $git, $force = false, $dryRun = false)
    {
        $this->diff = new Differ();
        $this->git = $git;
        $this->force = $force;
        $this->dryRun = $dryRun;

        if ( false === $dryRun){
            $this->setEventDispatcher($eventDispatcher);
        }
    }

    public function commitFix(FixerFileProcessedEvent $event)
    {
        if ($event->getStatus() !== FixerFileProcessedEvent::STATUS_FIXED ){
            return false;
        }

        $commitMessage = 'apply ' .str_replace('_', ' ',$event->getFileInfo()['appliedFixers'][0]);
        $file = $event->getFileInfo()['filename'];


        $this->git->add($file);
        die(var_dump($this->git->getOutput()));
/*
        if ( $this->force ){
            $this->git->add($file);
            $this->git->commit($commitMessage);
        }*/
    }

    /**
     * @param EventDispatcher $eventDispatcher
     */
    private function setEventDispatcher(EventDispatcher $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->eventDispatcher->addListener(FixerFileProcessedEvent::NAME, [$this, 'commitFix']);
    }
}
