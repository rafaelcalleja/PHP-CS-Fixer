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
use SebastianBergmann\Diff\Differ;
use Symfony\Component\EventDispatcher\EventDispatcher;

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

    public function precheckFile(FixerFileProcessedEvent $event)
    {
        if ($event->getStatus() !== FixerFileProcessedEvent::STATUS_START){
            return false;
        }

        $file = $event->getFileInfo()['filename'];
        if (false === empty((string) $this->git->diff($file))) {
            throw new \RuntimeException(sprintf("The file %s has changed uncommited, stash or commit them", $file));
        }
    }

    public function commitFix(FixerFileProcessedEvent $event)
    {
        if ($event->getStatus() !== FixerFileProcessedEvent::STATUS_APPLY ){
            return false;
        }

        $commitMessage = 'apply ' .str_replace('_', ' ', $event->getFileInfo()['appliedFixers']);
        $file = $event->getFileInfo()['filename'];
        $content =$event->getFileInfo()['content'];
        file_put_contents($event->getFileInfo()['realpath'], $content);
        $this->git->add($file);
        $this->git->commit($commitMessage);
    }

    /**
     * @param EventDispatcher $eventDispatcher
     */
    private function setEventDispatcher(EventDispatcher $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->eventDispatcher->addListener(FixerFileProcessedEvent::NAME, [$this, 'precheckFile']);
        $this->eventDispatcher->addListener(FixerFileProcessedEvent::NAME, [$this, 'commitFix']);
    }
}
