<?php
/*
 * This file is part of the Symfony CS utility.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace Symfony\CS\Fixer\Contrib;
use Symfony\CS\AbstractFixer;
use Symfony\CS\Tokenizer\Token;
use Symfony\CS\Tokenizer\Tokens;

class ExplicitConditionFixer extends AbstractFixer
{
    /**
     * {@inheritdoc}
     */
    public function fix(\SplFileInfo $file, $content)
    {
        $tokens = Tokens::fromCode($content);
        $tokens->clearEmptyTokens();

        $this->fixTokens($tokens);

        return $tokens->generateCode();
    }

    /**
     * @param Tokens $tokens
     * @param $index
     */
    private function fixCompositeComparison(Tokens $tokens, $index)
    {
        $firstNonSpace = $tokens->getNextMeaningfulToken($index); // Primer (

        if ( null !== $firstNonSpace ){
            $this->resolveBlock($tokens, $index, $firstNonSpace);
        }

        $this->resolveToken($tokens, $firstNonSpace ?: $index);

        return $index;
    }

    private function resolveBlock(Tokens $tokens, $index, $firstNonSpace){

        $blockType = $tokens->detectBlockType($tokens[$firstNonSpace]);

        if( $blockType ){
            $blockEndIndex = $tokens->findBlockEnd($blockType['type'], $firstNonSpace);
        }else{
            if (false === $tokens[$index]->equals('(')){
                return null;
            }
            $blockEndIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $index);
            $firstNonSpace = $index;

        }


        $ifContent = $tokens->generatePartialCode($firstNonSpace, $blockEndIndex); //string contenido del if
        $tokensContent = $this->createPHPTokensEncodingFromCode($ifContent);
        $tokensContent->clearEmptyTokens();

        foreach($tokens->findGivenKind([T_BOOLEAN_AND, T_BOOLEAN_OR,], $firstNonSpace, $blockEndIndex) as $token){
            if ( false === empty($token)) {
                $boolIndex = key($token);

                //left
                $startLeft = $this->findComparisonStart($tokens, $boolIndex-1);
                $endLeft = $tokens->getPrevNonWhitespace($boolIndex);

                $left = $this->createPHPTokensEncodingFromCode(
                    $tokens->generatePartialCode($startLeft, $endLeft)
                );

                $this->resolveToken($left, 0);
                for ($i = $startLeft; $i <= $endLeft; ++$i) {
                    $tokens[$i]->clear();
                }

                //right
                $startRight = $tokens->getNextNonWhitespace($boolIndex);
                $endRight = $this->findComparisonEnd($tokens, $boolIndex+1);


                $right = $this->createPHPTokensEncodingFromCode(
                    $tokens->generatePartialCode($startRight, $endRight)
                );
                //var_dump($right->generateCode());
                $this->fixCompositeComparison($right, 0);

                $this->resolveToken($right, 0);
                for ($i = $startRight; $i <= $endRight; ++$i) {
                    $tokens[$i]->clear();
                }

                $tokens->insertAt($startRight, $right);
                $tokens->insertAt($startLeft, $left);

            }

        }

    }



    private function resolveToken(Tokens $tokens, $index){

        $tokensVarsCollections = $tokens->findGivenKind([T_VARIABLE, T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_NOT_EQUAL]);

        if (false !== ($exclamationIndex = $this->findExclamation($tokens, $index)) && false == $tokens[$exclamationIndex+1]->isGivenKind([T_STRING])){
            $tokens[$exclamationIndex]->clear();
            $tokens->insertAt(
                $exclamationIndex,
                $this->createPHPTokensEncodingFromCode('false == ')
            );
        }elseif( count($tokensVarsCollections[T_VARIABLE]) == 1 ){ //$a

            if ( false === $this->hasEqualOperator($tokens) ){
                var_dump($index);
                $currentOrNext = $tokens->getNextMeaningfulToken($index) ?: $index;
                $tokens->insertAt(
                    $currentOrNext,
                    $this->createPHPTokensEncodingFromCode('true == ')
                );
            }

        }else{

        }

        return $index;


    }

    /**
     * @param Tokens $tokens
     * @return bool
     */
    private function hasEqualOperator(Tokens $tokens){
        $comparisons = $this->getComparisonTypes($tokens);

        return false === empty($comparisons);
    }

    /**
     * @param Tokens $tokens
     * @return array
     */
    private function getComparisonTypes(Tokens $tokens){
        $comparisons = $tokens->findGivenKind(array(T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_NOT_EQUAL));
        $comparisons = array_merge(
            array_keys($comparisons[T_IS_EQUAL]),
            array_keys($comparisons[T_IS_IDENTICAL]),
            array_keys($comparisons[T_IS_NOT_IDENTICAL]),
            array_keys($comparisons[T_IS_NOT_EQUAL])
        );
        sort($comparisons);
        return array_reverse($comparisons);
    }

    private function isVariable(Tokens $tokens, $start, $end)
    {
var_dump("$start, $end", $tokens[$start]->getContent());
        if ($end === $start) {
            return $tokens[$start]->isGivenKind(T_VARIABLE);
        }

        $index = $start;
        $expectString = false;

        while ($index <= $end) {

            $current = $tokens[$index];
            // check if this is the last token
            if ($index === $end) {
                return $current->isGivenKind($expectString ? T_STRING : T_VARIABLE);
            }

            $next = $tokens[$index + 1];
            // self:: or ClassName::
            if ($current->isGivenKind(T_STRING) && $next->isGivenKind(T_DOUBLE_COLON)) {
                $index += 2;
                continue;
            }
            // \ClassName
            if ($current->isGivenKind(T_NS_SEPARATOR) && $next->isGivenKind(T_STRING)) {
                ++$index;
                continue;
            }
            // ClassName\
            if ($current->isGivenKind(T_STRING) && $next->isGivenKind(T_NS_SEPARATOR)) {
                $index += 2;
                continue;
            }
            // $a-> or a-> (as in $b->a->c)
            if ($current->isGivenKind($expectString ? T_STRING : T_VARIABLE) && $next->isGivenKind(T_OBJECT_OPERATOR)) {
                $index += 2;
                $expectString = true;
                continue;
            }
            // {...} (as in $a->{$b})
            if ($expectString && $current->isGivenKind(CT_DYNAMIC_PROP_BRACE_OPEN)) {
                $index = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_DYNAMIC_PROP_BRACE, $index);
                if ($index === $end) {
                    return true;
                }
                if ($index > $end) {
                    return false;
                }
                ++$index;
                if (!$tokens[$index]->isGivenKind(T_OBJECT_OPERATOR)) {
                    return false;
                }
                ++$index;
                continue;
            }
            // $a[...] or a[...] (as in $c->a[$b])
            if ($current->isGivenKind($expectString ? T_STRING : T_VARIABLE) && $next->equals('[')) {
                $index = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_SQUARE_BRACE, $index + 1);
                if ($index === $end) {
                    return true;
                }
                if ($index > $end) {
                    return false;
                }
                ++$index;
                if (!$tokens[$index]->isGivenKind(T_OBJECT_OPERATOR)) {
                    return false;
                }
                ++$index;
                $expectString = true;
                continue;
            }

            /** method($params) native call*/
            if ($current->isGivenKind($expectString ? T_VARIABLE : T_STRING) && $next->equals('(')) {

                if ( function_exists($tokens[$index]->getContent() )){ return true;}
                $index = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $index +1);

                if ($index === $end) {
                    return true;
                }

                if ($index > $end) {
                    return false;
                }

                ++$index;
                if (!$tokens[$index]->isGivenKind(T_OBJECT_OPERATOR)) {
                    return false;
                }
                ++$index;

                $expectString = true;
                continue;
            }
            return false;
        }

        return false;
    }

    /**
     * @param $code
     * @return Tokens
     */
    private function createPHPTokensEncodingFromCode($code){
        $phpTokens = Tokens::fromCode("<?php $code");
        $phpTokens[0]->clear();
        $phpTokens->clearEmptyTokens();
        return $phpTokens;
    }

    private function findOpenBrace(Tokens $tokens, $index){
        return $this->findSingleChar($tokens, $index, '(');
    }

    private function findExclamation(Tokens $tokens, $index){
        return $this->findSingleChar($tokens, $index, '!');
    }

    private function findSingleChar(Tokens $tokens, $index, $char){

        for($x = $index;$x < count($tokens); $x++){
            if ($tokens[$x]->getContent() === $char) return $x;
        }

        return false;
    }

    private function fixTokens(Tokens $tokens)
    {

        $comparisons = $tokens->findGivenKind(array(T_IF, T_ELSEIF));

        $comparisons = array_merge(
            array_keys($comparisons[T_IF]),
            array_keys($comparisons[T_ELSEIF])
        );

        sort($comparisons);
        $lastFixedIndex = count($tokens);

        foreach (array_reverse($comparisons) as $index) {
            if ($index >= $lastFixedIndex) {

                continue;
            }

            $lastFixedIndex = $this->fixCompositeComparison($tokens, $index);
        }
    }
    private function updateIndexPosition(Tokens $tokens, $index){

        $startLeft = $this->findComparisonStart($tokens, $index);
        $endLeft = $tokens->getPrevNonWhitespace($index);
        $startRight = $tokens->getNextNonWhitespace($index);
        $endRight = $this->findComparisonEnd($tokens, $index);

        return array($startLeft, $endLeft, $startRight, $endRight);

    }
   /**
     *
     * @param Tokens $tokens The token list
     * @param int    $index  The index of the comparison
     *
     * @return int The first index of the left-hand side of the comparison
     */
    private function findComparisonStart(Tokens $tokens, $index)
    {
        while (0 <= $index) {

            $token = $tokens[$index];

            if ($this->isTokenOfLowerPrecedence($token)) {
                break;//break on return
            }

            $block = $tokens->detectBlockType($token);
            if (null === $block) {
                --$index;
                continue;
            }

            if ($block['isStart']) {

                break;
            }

            $index = $tokens->findBlockEnd($block['type'], $index, false) - 1;
        }

        return $tokens->getNextNonWhitespace($index);
    }

    /**
     * @param Tokens $tokens The token list
     * @param int    $index  The index of the comparison
     *
     * @return int The last index of the right-hand side of the comparison
     */
    private function findComparisonEnd(Tokens $tokens, $index)
    {
        $count = count($tokens);
        while ($index < $count) {
            $token = $tokens[$index];
            if ($this->isTokenOfLowerPrecedence($token)) {
                break;
            }
            $block = $tokens->detectBlockType($token);
            if (null === $block) {
                ++$index;
                continue;
            }
            if (!$block['isStart']) {
                break;
            }
            $index = $tokens->findBlockEnd($block['type'], $index) + 1;
        }

        return $tokens->getPrevNonWhitespace($index);
    }

    /**
     * @param Token $token The token to check
     *
     * @return bool Whether the token has a lower precedence
     */
    private function isTokenOfLowerPrecedence(Token $token)
    {
        static $tokens;
        if (null === $tokens) {
            $tokens = array(
                // '&&', '||',
                T_BOOLEAN_AND, T_BOOLEAN_OR,
                // '.=', '/=', '-=', '%=', '*=', '+=',
                T_CONCAT_EQUAL, T_DIV_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_PLUS_EQUAL,
                // '&=', '|=', '^=',
                T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL,
                // '<<=', '>>=', '=>',
                T_SL_EQUAL, T_SR_EQUAL, T_DOUBLE_ARROW,
                // 'and', 'or', 'xor',
                T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR,
                // keywords like 'return'
                T_RETURN, T_THROW, T_GOTO, T_CASE,
            );
            // PHP 5.6 introduced **=
            if (defined('T_POW_EQUAL')) {
                $tokens[] = constant('T_POW_EQUAL');
            }
        }
        static $otherTokens = array(
            // bitwise and, or, xor
            '&', '|', '^',
            // ternary operators
            '?', ':',
            // assignment
            '=',
            // end of PHP statement
            ',', ';',
        );
        return $token->isGivenKind($tokens) || $token->equalsAny($otherTokens);
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription()
    {
        return 'Enforce explicit conditional on expressions.';
    }
}
