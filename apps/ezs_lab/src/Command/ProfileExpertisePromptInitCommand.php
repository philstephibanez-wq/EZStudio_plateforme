<?php

declare(strict_types=1);
namespace EZSLab\Command;
use EZSLab\Service\ProfileExpertisePrompt;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name: 'ezs:profile:prompt:init', description: 'Initialize the LAB expertise prompt')]
final class ProfileExpertisePromptInitCommand extends Command
{
    public function __construct(private readonly ProfileExpertisePrompt $prompt) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('PROFILE_PROMPT_ACTIVE=#'.$this->prompt->initialize());
        return Command::SUCCESS;
    }
}
