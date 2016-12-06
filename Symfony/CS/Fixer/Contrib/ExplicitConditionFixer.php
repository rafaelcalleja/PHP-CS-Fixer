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
        $this->resolveBlock($tokens, $index);
        $this->resolveToken($tokens, $index);

        return $index;
    }

    /**
     * @param Tokens $tokens
     * @return array
     */
    private function getEqualsTypes(Tokens $tokens,array $type, $start, $end)
    {
        $ret = [];
        $index = $start;

        while ($index <= $end){
            foreach ($type as $key) {
                $ret[] = $tokens->getTokenOfKindSibling($index, +1, [$key]);
            }
            $index++;
        }

        $ret = array_unique(array_filter($ret));
        sort($ret);

        return array_reverse($ret);
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

    /**
     * @param Tokens $tokens
     * @param $index
     * @return null
     */
    private function resolveBlock(Tokens $tokens, $index){

        $blockType = $tokens->detectBlockType($tokens[$index]);

        if( $blockType ){
            $blockEndIndex = $tokens->findBlockEnd($blockType['type'], $index);
        }else{
            if (false === $tokens[$index]->equals('(')){
                return null;
            }
            $blockEndIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $index);
        }

        $ifContent = $tokens->generatePartialCode($index, $blockEndIndex);


        $tokensContent = $this->createPHPTokensEncodingFromCode($ifContent);
        $tokensContent->clearEmptyTokens();

        $reverseIndexsOfToken = $this->getReverseKindTypes($tokens, [T_BOOLEAN_AND, T_BOOLEAN_OR,], $index, $blockEndIndex);

        foreach($reverseIndexsOfToken as $boolIndex) {
            $startLeft = $this->findComparisonStart($tokens, $boolIndex - 1);
            $endLeft = $tokens->getPrevNonWhitespace($boolIndex);

            $left = $this->createPHPTokensEncodingFromCode(
                $tokens->generatePartialCode($startLeft, $endLeft)
            );

            $currentOrNextLeft = $left->getNextMeaningfulToken(0) ?: 0;

            $leftInsert = false;
            if (
                (
                    $this->isVariable($left, $currentOrNextLeft, count($left) - 1) ||
                    null !== $this->getIndexStaticMethodCall($left)

                )
                && false === $this->hasBinaryOperator($left)) {

                $this->resolveToken($left, 0);
                for ($i = $startLeft; $i <= $endLeft; ++$i) {
                    $tokens[$i]->clear();
                }
                $leftInsert = true;
            }


            $startRight = $tokens->getNextNonWhitespace($boolIndex);

            $endRight = $this->findComparisonEnd($tokens, $boolIndex + 1);

            $right = $this->createPHPTokensEncodingFromCode(
                $tokens->generatePartialCode($startRight, $endRight)
            );

            if (false === empty($this->getReverseKindTypes($right, [T_BOOLEAN_AND, T_BOOLEAN_OR,], 0, count($right) - 1))) {
                $this->fixCompositeComparison($right, 0);
            } else {
                $this->resolveToken($right, 0);

                for ($i = $startRight; $i <= $endRight; ++$i) {
                    $tokens[$i]->clear();
                }

                $tokens->insertAt($startRight, $right);
            }

            if ( $leftInsert ){
                $tokens->insertAt($startLeft, $left);

            }
        }
    }

    /**
     * @param Tokens $tokens
     * @return bool
     */
    private function hasBinaryOperator(Tokens $tokens){
        $index = 0;

        return count(array_filter($tokens->toArray(), function() use($tokens, &$index) {
            return $tokens->isBinaryOperator($index++);
        })) > 0;
    }

    /**
     * @param Tokens $tokens
     * @param array $types
     * @param $start
     * @param $end
     * @return bool
     */
    private function hasGivenType(Tokens $tokens, array $types, $start, $end){
        $count = 0;
        foreach($tokens->findGivenKind($types, $start, $end) as $type){
            if (false === empty($type)){
                $count++;
            }
        }
        return $count > 0;
    }

    /**
     * @param Tokens $tokens The token list
     * @param int    $start  The first index of the possible variable
     * @param int    $end    The last index of the possible varaible
     *
     * @return bool Whether the tokens describe a variable
     */
    private function isVariable(Tokens $tokens, $start, $end)
    {

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

            if( null !== $this->getIndexInstanceOf($tokens, $index, $end)) {
                return true;
            }


            return false;
        }

        return false;
    }

    /**
     * @param $name
     * @return bool
     */
    public function hasBoolReturnValueByFuncName($name){
        static $boolFunctions = array(
            'boolval',
        );
        if ( !function_exists($name) ){
            return false;
        }

        return strpos($name, 'is_') === 0 || in_array($name, $boolFunctions) ;
    }

    /**
     * @param Tokens $tokens
     * @param bool $start
     * @param bool $end
     * @return mixed|null
     */
    private function getIndexInstanceOf(Tokens $tokens, $start = false, $end = false){

        if ($start === false){
            $start = 0;
        }

        if ($end === false){
            $end = count($tokens) - 1;
        }

        $seq = array(
            [T_VARIABLE],
            [T_INSTANCEOF],
        );

        $resolved = $tokens->findSequence($seq, $start, $end);

        if ( null === $resolved){
            return null;
        }

        $keysIndexs = array_keys($resolved);

        return end($keysIndexs);


    }

    /**
     * @param Tokens $tokens
     * @param bool $start
     * @param bool $end
     * @return mixed|null
     */
    private function getIndexStaticMethodCall(Tokens $tokens, $start = false, $end = false){

        if ($start === false){
            $start = 0;
        }

        if ($end === false){
            $end = count($tokens) - 1;
        }

        $seq = array(
            [T_STRING],
            [T_DOUBLE_COLON],
            [T_STRING],
            '(',
        );

        $resolved = $tokens->findSequence($seq, $start, $end);

        if ( null === $resolved){
            return null;
        }

        $keysIndexs = array_keys($resolved);

        return end($keysIndexs);
    }

    /**
     * @param Tokens $tokens
     * @param $index
     * @return mixed
     */
    private function resolveToken(Tokens $tokens, $index){
        $currentOrNext = $tokens->getNextMeaningfulToken($index) ?: $index;

        if ($tokens[$index]->equals('!')){
            $currentOrNext = $index;
        }

        $blockEnd = $this->findComparisonEnd($tokens, $currentOrNext);

        $blockTokens = ($blockEnd > 0) ? $this->createPHPTokensEncodingFromCode(
            $tokens->generatePartialCode($tokens->getNextMeaningfulToken($index),
                $blockEnd
            )
        ) : $tokens;

        if ( $this->hasEqualOperator($blockTokens) || $this->hasBinaryOperator($blockTokens)) return $index;

        recheck:

        list($tempIndex, $nextComparison) = $this->boundIndex($tokens, $index);
        $currentComparison = $this->creteTokensFromBounds($tokens, $tempIndex, $nextComparison);

        if (false === $this->hasEqualOperator($blockTokens) && false === $this->hasEqualOperator($currentComparison) && $this->isVariable($blockTokens, 0, count($blockTokens) -1)){
            $booleanComparator = 'true';

            if ($tokens[$currentOrNext]->equals('!')){
                $booleanComparator = 'false';
                $tokens[$currentOrNext]->clear();
                $tokens->clearEmptyTokens();
            }

            if ($tokens[$currentOrNext]->isGivenKind([T_OBJECT_OPERATOR, T_DOUBLE_COLON])){
                $currentOrNext--;
            }

            $comparsionStrict = $this->isStrictComparsion($tokens[$currentOrNext]) ? ' === ' : ' == ';

            if ( null !== $this->getIndexInstanceOf($blockTokens) ){
                $comparsionStrict = ' === ';
            }

            if ($tokens[$currentOrNext]->isGivenKind([T_OBJECT_OPERATOR, T_DOUBLE_COLON])){
                $currentOrNext--;
            }

            $block = $tokens->detectBlockType($tokens[$currentOrNext]);

            if( $block !== null ){
                $currentOrNext = $tokens->getPrevMeaningfulToken($index);
            }

            $tokens->insertAt(
                $currentOrNext,
                $this->createPHPTokensEncodingFromCode($booleanComparator.$comparsionStrict)
            );

        }else{

            if ($index < $nextComparison-1){
                $index++;

                $continue = $tokens->getNextMeaningfulToken($index) ?: $index;
                $blockEnd = $this->findComparisonEnd($tokens, $continue);

                $blockTokens = ($blockEnd > 0) ? $this->createPHPTokensEncodingFromCode(
                    $tokens->generatePartialCode($tokens->getNextMeaningfulToken($index),
                        $blockEnd
                    )
                ) : $tokens;

                goto recheck;
            }

        }

        return $index;
    }

    /**
     * @param Tokens $tokens
     * @param $index
     * @param int $direction
     * @return int|null
     */
    private function getNextMeaningfulToken(Tokens $tokens, $index, $direction = 1)
    {
        if ( $this->isVariable($tokens, $index, count($tokens) - 1) ) return $index;

        return $tokens->getTokenNotOfKindSibling(
            $index,
            $direction,
            array(array(T_WHITESPACE), array(T_COMMENT), array(T_DOC_COMMENT))
        );
    }


    /**
     * @param Token $token
     * @return bool
     */
    private function isStrictComparsion(Token $token){

        static $tokensList;

        if (null === $tokensList) {
            $tokensList = array(
                T_ISSET,
                T_EMPTY,
                T_INSTANCEOF
            );
        }

        return $token->isGivenKind($tokensList) || $this->hasBoolReturnValueByFuncName($token->getContent());
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

    /**
     * @param Tokens $tokens
     */
    private function fixTokens(Tokens $tokens)
    {
        $comparisons = $this->getReverseKindTypes($tokens,[T_IF, T_ELSEIF], 0, count($tokens) -1);
        $otherComparisons = $this->getEqualsTypes($tokens,['='], 0, count($tokens) -1);

        $comparisons = $comparisons + $otherComparisons;
        sort($comparisons);

        $lastFixedIndex = count($tokens);

        foreach (array_reverse($comparisons) as $index) {
            if ($index >= $lastFixedIndex) {
                continue;
            }
            $index = $tokens->getNextMeaningfulToken($index) ?: $index;
            $lastFixedIndex = $this->fixCompositeComparison($tokens, $index);
        }
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
            $tokens = $this->getTokenOfLowerPrecedence();
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

    /**
     * @param Tokens $tokens
     * @param $index
     * @return array
     */
    private function boundIndex(Tokens $tokens, $index)
    {
        $tempIndex = $index;
        while ($tempIndex >= 0) {

            if ($this->isTokenOfLowerPrecedence($tokens[$tempIndex])) {
                break;
            }
            $tempIndex--;
        }


        $nextComparison = $tempIndex + 1;

        while ($nextComparison < count($tokens) - 1) {
            if ($this->isTokenOfLowerPrecedence($tokens[$nextComparison])) {
                break;
            }
            $nextComparison++;
        }
        $tempIndex = $tempIndex < 0 ? 0 : $tempIndex;
        $nextComparison = $nextComparison >= count($tokens) ? count($tokens) - 1 : $nextComparison;

        return array($tempIndex, $nextComparison);
    }

    /**
     * @param Tokens $tokens
     * @param $tempIndex
     * @param $nextComparison
     * @return Tokens
     */
    private function creteTokensFromBounds(Tokens $tokens, $tempIndex, $nextComparison)
    {
        $theTokens = clone $tokens;

        $currentComparison = $this->createPHPTokensEncodingFromCode(
            $theTokens->generatePartialCode($tempIndex, $nextComparison)
        );

        return $currentComparison;
    }

    /**
     * @return array
     */
    private function getTokenOfLowerPrecedence()
    {
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
            return $tokens;
        }
        return $tokens;
    }
}
