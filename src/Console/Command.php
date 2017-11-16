<?php

namespace SimonHamp\NetworkElements\Console;

use Illuminate\Console\Command as BaseCommand;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Exception\LogicException;

class Command extends BaseCommand
{
    /**
     * Prompt the user for input but hide the answer from the console.
     *
     * @param  string  $question
     * @param  bool    $fallback
     * @param  bool    $optional
     * @return string
     */
    public function secret($question, $fallback = true, $optional = false)
    {
        $question = new Question($question);

        $validator = function ($value) use ($optional) {
            if (! $optional) {
                if (!is_array($value) && !is_bool($value) && 0 === strlen($value)) {
                    throw new LogicException('A value is required.');
                }
            }

            return $value;
        };

        $question->setValidator($validator)->setHidden(true)->setHiddenFallback($fallback);

        return $this->output->askQuestion($question);
    }
}
