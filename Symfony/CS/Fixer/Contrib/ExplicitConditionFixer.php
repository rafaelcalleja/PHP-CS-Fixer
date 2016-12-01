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

        if ( $firstNonSpace && $tokens->isUnaryPredecessorOperator($index) ){
            $firstNonSpace = $index;
        }
        $this->resolveToken($tokens, $firstNonSpace ?: $index);

        return $index;
    }

    /**
     * @param Tokens $tokens
     * @return array
     */
    private function getReverseKindTypes(Tokens $tokens, $type, $start, $end){
        $comparisons = $tokens->findGivenKind($type, $start, $end);
        $ret = [];

        foreach($type as $key){
            $ret = array_merge($ret, array_keys($comparisons[$key]));
        }
        sort($ret);
        return array_reverse($ret);
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

        $reverseIndexsOfToken = $this->getReverseKindTypes($tokens, [T_BOOLEAN_AND, T_BOOLEAN_OR,], $firstNonSpace, $blockEndIndex);

        foreach($reverseIndexsOfToken as $boolIndex){

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
           // var_dump('LEFT:'.$left->generateCode());
            //right
            $startRight = $tokens->getNextNonWhitespace($boolIndex);
            $endRight = $this->findComparisonEnd($tokens, $boolIndex+1);


            $right = $this->createPHPTokensEncodingFromCode(
                $tokens->generatePartialCode($startRight, $endRight)
            );

            $this->fixCompositeComparison($right, 0);

            $this->resolveToken($right, 0);
            for ($i = $startRight; $i <= $endRight; ++$i) {
                $tokens[$i]->clear();
            }
          //  var_dump('RIGHT:'.$right->generateCode());

            $tokens->insertAt($startRight, $right);
            $tokens->insertAt($startLeft, $left);

        }
    }

    private function hasGivenType(Tokens $tokens,array $types, $start, $end){
        $count = 0;
        foreach($tokens->findGivenKind($types, $start, $end) as $type){
            if (false === empty($type)){
                $count++;
            }
        }
        return $count > 0;
    }



    private function resolveToken(Tokens $tokens, $index){


        $currentOrNext = $tokens->getNextMeaningfulToken($index) ?: $index;
        $blockEnd = $this->findComparisonEnd($tokens, $currentOrNext);

        $blockTokens = ($blockEnd > 0) ? $this->createPHPTokensEncodingFromCode(
            $tokens->generatePartialCode($tokens->getNextMeaningfulToken($index),
                $blockEnd
            )
        ) : $tokens;

        if ( $blockTokens[0]->isGivenKind())

        var_dump('NOMRLA:'. $tokens->generatePartialCode($index, $blockEnd));
        var_dump('next:'. $tokens->generatePartialCode($currentOrNext, $blockEnd));



        $tokensVarsCollections = $blockTokens->findGivenKind([T_VARIABLE, T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_NOT_EQUAL]);

        if (false !== ($exclamationIndex = $this->isExclamation($tokens, $index)) && false == $tokens[$exclamationIndex+1]->isGivenKind([T_STRING])){
            $currentOrNext = $tokens->getNextMeaningfulToken($exclamationIndex) ?: $exclamationIndex;

            $comparsionStrict = $this->isStrictComparsion($tokens[$currentOrNext]) ? 'false === ' : 'false == ';
            
            $tokens[$exclamationIndex]->clear();
            $tokens->insertAt(
                $exclamationIndex,
                $this->createPHPTokensEncodingFromCode($comparsionStrict)
            );
        } else {
  //          var_dump( $blockTokens->generateCode());
//var_dump($index, $currentOrNext );
            if ( false === $this->hasEqualOperator($blockTokens) && count($tokensVarsCollections[T_VARIABLE]) == 1){

                $comparsionStrict = $this->isStrictComparsion($tokens[$currentOrNext]) ? 'true === ' : 'true == ';
                $tokens->insertAt(
                    $currentOrNext,
                    $this->createPHPTokensEncodingFromCode($comparsionStrict)
                );
            }


        }

        return $index;


    }

    private function isStrictComparsion(Token $token){

        static $tokensList;

        if (null === $tokensList) {
            $tokensList = array(
                T_ISSET
            );
        }

        static $otherTokens = array(
            'is_null',
        );

        return $token->isGivenKind($tokensList) || $token->equalsAny($otherTokens);
    }

    /**
     * @param Tokens $tokens
     * @return bool
     */
    private function hasEqualOperator(Tokens $tokens){
        $comparisons = $this->hasGivenType($tokens, array(T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_NOT_EQUAL), 0, count($tokens) -1);
        return $comparisons;
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

    private function isExclamation(Tokens $tokens, $index){

        if ( $tokens[$index]->equals('(')){
            $index = $tokens->getNextMeaningfulToken($index);
        }

        return (
            $tokens->isUnaryPredecessorOperator($index)  &&
            $tokens[$index]->equals('!')
        ) ? $index :  false;
    }

    private function fixTokens(Tokens $tokens)
    {

        $comparisons = $this->getReverseKindTypes($tokens,[T_IF, T_ELSEIF], 0, count($tokens) -1);

        $lastFixedIndex = count($tokens);


        foreach ($comparisons as $index) {
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
                // if elseif
                T_IF, T_ELSEIF,
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
