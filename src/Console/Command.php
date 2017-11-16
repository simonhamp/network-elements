<?php

namespace SimonHamp\NetworkElements\Console;

use Illuminate\Console\Command as BaseCommand;
use Symfony\Component\Console\Question\Question;

class Command extends BaseCommand
{
    /**
     * Prompt the user for input but hide the answer from the console.
     *
     * @param  string  $question
     * @param  bool    $fallback
     * @param  string  $default
     * @return string
     */
    public function secret($question, $fallback = true, $default = null)
    {
        $question = new Question($question, $default);

        $question->setHidden(true)->setHiddenFallback($fallback);

        return $this->output->askQuestion($question);
    }
}
