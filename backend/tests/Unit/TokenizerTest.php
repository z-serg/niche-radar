<?php

namespace Tests\Unit;

use App\Support\Tokenizer;
use PHPUnit\Framework\TestCase;

class TokenizerTest extends TestCase
{
    public function test_tokens_are_unicode_letter_or_digit_sequences(): void
    {
        $this->assertSame(['конвертер', 'pdf', 'в', 'word'], Tokenizer::tokens('конвертер pdf→в word!'));
        $this->assertSame(['маркет', 'плейс', '2026'], Tokenizer::tokens('маркет-плейс 2026'));
        $this->assertSame([], Tokenizer::tokens('!!! ...'));
    }

    public function test_word_and_char_counts(): void
    {
        $phrase = 'чемпионат мира матчи';

        $this->assertSame(3, Tokenizer::wordCount($phrase));
        $this->assertSame(20, Tokenizer::charCount($phrase)); // совпадает с «Символов» образца
    }

    public function test_bigrams_are_adjacent_pairs(): void
    {
        $this->assertSame(
            ['конвертер pdf', 'pdf в', 'в word'],
            Tokenizer::bigrams(['конвертер', 'pdf', 'в', 'word'])
        );
    }

    public function test_search_tokens_lowercase(): void
    {
        $this->assertSame(['pdf', 'word'], Tokenizer::searchTokens('PDF Word'));
    }
}
