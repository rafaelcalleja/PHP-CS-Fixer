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
       // var_dump('fix hasta:'.  $currentComparison->generateCode());

        $firstNonSpace = $this->getNextMeaningfulToken($tokens, $index); // Primer (

        if ( null !== $firstNonSpace ){
            //var_dump('resolveBlock ' . $tokens->generateCode());
            $this->resolveBlock($tokens, $index, $firstNonSpace);
        }

        if ( $firstNonSpace && $tokens->isUnaryPredecessorOperator($index) ){
            $firstNonSpace = $index;
        }

        //var_dump('resolveToken ' . $tokens->generateCode());
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

        foreach($reverseIndexsOfToken as $boolIndex) {

            //left
            $startLeft = $this->findComparisonStart($tokens, $boolIndex - 1);
            $endLeft = $tokens->getPrevNonWhitespace($boolIndex);

            $left = $this->createPHPTokensEncodingFromCode(
                $tokens->generatePartialCode($startLeft, $endLeft)
            );

            $currentOrNextLeft = $left->getNextMeaningfulToken(0) ?: 0;

            $leftInsert = false;
            if ($this->isVariable($left, $currentOrNextLeft, count($left) - 1) && false === $this->hasBinaryOperator($left)) {

                $this->resolveToken($left, 0);
                for ($i = $startLeft; $i <= $endLeft; ++$i) {
                    $tokens[$i]->clear();
                }
                $leftInsert = true;
            }

            //var_dump('LEFT:' . $left->generateCode());


            //right
            list($tempIndex, $nextComparison) = $this->boundIndex($tokens, $boolIndex);
            $startRight = $tokens->getNextNonWhitespace($boolIndex);
            //var_dump($tokens[$tempIndex]);
            $endRight = $this->findComparisonEnd($tokens, $boolIndex + 1);


            $right = $this->createPHPTokensEncodingFromCode(
                $tokens->generatePartialCode($startRight, $endRight)
            );
            //var_dump('RIGHT:'.$right->generateCode());
            if (false === empty($this->getReverseKindTypes($right, [T_BOOLEAN_AND, T_BOOLEAN_OR,], 0, count($right) - 1))) {

                $this->fixCompositeComparison($right, 0);

            } else {
            //

            $rightnsert = false;

            //if ( $this->isVariable($right, 0, count($right) -1) && false === $this->hasBinaryOperator($right)){

                $this->resolveToken($right, 0);

                for ($i = $startRight; $i <= $endRight; ++$i) {
                    $tokens[$i]->clear();
                }

                $rightnsert = true;
                $tokens->insertAt($startRight, $right);
                //var_dump('RIGHT:'.$right->generateCode());
            //}
            }


           // if($rightnsert)var_dump('RIGHT:'.$right->generateCode());


            if ( $leftInsert ){
                $tokens->insertAt($startLeft, $left);

            }else{

              //  var_dump('WHEN NO'.$startLeft);
            }
        }
    }

    private function hasBinaryOperator(Tokens $tokens){

        $index = 0;
        return count(array_filter($tokens->toArray(), function($token) use($tokens, &$index) {
            return $tokens->isBinaryOperator($index++);
        })) > 0;
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



    /**
     * Checks whether the tokens between the given start and end describe a
     * variable.
     *
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

            return false;
        }

        return false;
    }


    public function hasBoolReturnValueByFuncName($name){
        static $boolFunctions = array(
            'boolval',
        );
        if ( !function_exists($name) ){
            return false;
        }

        return strpos($name, 'is_') === 0 || in_array($name, $boolFunctions) ;
    }


    private function resolveToken(Tokens $tokens, $index){


        $currentOrNext = $tokens->getNextMeaningfulToken($index) ?: $index;
        $blockEnd = $this->findComparisonEnd($tokens, $currentOrNext);

        $blockTokens = ($blockEnd > 0) ? $this->createPHPTokensEncodingFromCode(
            $tokens->generatePartialCode($tokens->getNextMeaningfulToken($index),
                $blockEnd
            )
        ) : $tokens;

        if ( $this->hasEqualOperator($blockTokens)) return $index;

        /*var_dump('NOMRLA:'. $tokens->generatePartialCode($index, $blockEnd));
        var_dump('next:'. $tokens->generatePartialCode($currentOrNext, $blockEnd),
            $blockTokens->generateCode(),
            $this->isVariable($blockTokens, 0, count($blockTokens) -1));*/
        /*list($tempIndex, $nextComparison) = $this->boundIndex($tokens, $index);
        $currentComparison = $this->creteTokensFromBounds($tokens, $tempIndex, $nextComparison);*/
       // var_dump('CURRENT '.$blockTokens->generateCode(),false === $this->hasEqualOperator($currentComparison) );
       // var_dump($tokens[$this->isExclamation($tokens, $index)+1]->isGivenKind([T_STRING]));


        if (false !== ($exclamationIndex = $this->isExclamation($tokens, $index)) &&
            (
                false == $tokens[$exclamationIndex+1]->isGivenKind([T_STRING]) ||
                ( true == $tokens[$exclamationIndex+1]->isGivenKind([T_STRING]) && $tokens[$exclamationIndex+2]->equals('('))
            )
        ){
            $currentOrNext = $tokens->getNextMeaningfulToken($exclamationIndex) ?: $exclamationIndex;

            $comparsionStrict = $this->isStrictComparsion($tokens[$currentOrNext]) ? 'false === ' : 'false == ';
            
            $tokens[$exclamationIndex]->clear();
            $tokens->insertAt(
                $exclamationIndex,
                $this->createPHPTokensEncodingFromCode($comparsionStrict)
            );
        } else {
            recheck:

            //var_dump( $blockTokens->generateCode() . ' ' . strval($this->hasEqualOperator($blockTokens)));
//var_dump($index, $currentOrNext );
            list($tempIndex, $nextComparison) = $this->boundIndex($tokens, $index);
            $currentComparison = $this->creteTokensFromBounds($tokens, $tempIndex, $nextComparison);
//var_dump('$currentComparison ' .$currentComparison->generateCode());
            if (false === $this->hasEqualOperator($blockTokens) && false === $this->hasEqualOperator($currentComparison) && $this->isVariable($blockTokens, 0, count($blockTokens) -1)){
                $comparsionStrict = $this->isStrictComparsion($tokens[$currentOrNext]) ? 'true === ' : 'true == ';
                //var_dump('INSERT AT ' . (string) $currentOrNext . ' indexof ' .(string) $index. ' tempIndex ' .(string) $tempIndex);

                if ($tokens[$currentOrNext]->isGivenKind(T_OBJECT_OPERATOR))$currentOrNext--;

                $tokens->insertAt(
                    $currentOrNext,
                    $this->createPHPTokensEncodingFromCode($comparsionStrict)
                );

//                var_dump('FIXED ' .$blockTokens->generateCode(), "$currentOrNext : $blockEnd");

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
                    //var_dump($tokens->generateCode());

                    goto recheck;
                }




              //  var_dump($blockTokens->generateCode(), $this->isVariable($blockTokens, 0, count($blockTokens) -1));
            }


        }

        return $index;


    }

    private function getNextMeaningfulToken(Tokens $tokens, $index, $direction = 1)
    {
        if ( $this->isVariable($tokens, $index, count($tokens) - 1) ) return $index;

        return $tokens->getTokenNotOfKindSibling(
            $index,
            $direction,
            array(array(T_WHITESPACE), array(T_COMMENT), array(T_DOC_COMMENT))
        );
    }


    private function isStrictComparsion(Token $token){

        static $tokensList;

        if (null === $tokensList) {
            $tokensList = array(
                T_ISSET,
                T_EMPTY,
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

    private function isExclamation(Tokens $tokens, $index){

        if ( $tokens[$index]->equals('(')){
            $index = $this->getNextMeaningfulToken($tokens, $index);
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
        //var_dump($tempIndex, $nextComparison);

        $currentComparison = $this->createPHPTokensEncodingFromCode(
            $theTokens->generatePartialCode($tempIndex, $nextComparison)
        );

        return $currentComparison;
    }

    /**
     * @param $blockTokens
     */
    private function isVariableOrMethodCall($blockTokens)
    {
        $methodCall = false;
       /** @var Token $token */
        if(count($blockTokens) > 1){
            $token = $blockTokens[1];
            $methodCall = $token->isGivenKind(T_OBJECT_OPERATOR);
        }


       return true === $this->isVariable($blockTokens, 0, count($blockTokens) - 1) ||
            false === $this->isVariable($blockTokens, 0, count($blockTokens) - 1) &&
            true === $methodCall
           ;
    }
}
