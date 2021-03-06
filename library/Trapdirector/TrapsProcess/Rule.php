<?php

namespace Trapdirector;

use Exception;

class Rule
{
    use \RuleUtils;
    
    /** @var Logging $logging logging class*/
    protected $logging;
    
    /** @var Trap $trapClass */
    protected $trapClass;
    

    /**
     * Setup Rule Class
     * @param Trap $trapClass : To get logging class & plugin class
     */
    function __construct($trapClass)
    {
        $this->trapClass=$trapClass;
        $this->logging=$trapClass->logging;
    }
    
    
    protected function eval_getElement($rule,&$item)
    {
        if ($item >= strlen($rule))
        {
            throw new Exception("Early end of string ".$rule ." at " .$item );
        }
        while ($rule[$item]==' ') $item++;
        if (preg_match('/[\-0-9\.]/',$rule[$item]))
        { // number
            return $this->get_number($rule, $item);
        }
        if ($rule[$item] == '"')
        { // string
            return $this->get_string($rule, $item);
        }
        
        if ($rule[$item] == '(')
        { // grouping
            return $this->get_group($rule, $item);
        }
        if ($rule[$item] == '_')
        { // function
            return $this->get_function($rule, $item);
        }
        throw new Exception("number/string not found in ".$rule ." at " .$item . ' : ' .$rule[$item]);
        
    }
    
    protected function eval_getOper($rule,&$item)
    {
        while ($rule[$item]==' ') $item++;
        switch ($rule[$item])
        {
            case '<':
                if ($rule[$item+1]=='=') { $item+=2; return array(0,"<=");}
                $item++; return array(0,"<");
            case '>':
                if ($rule[$item+1]=='=') { $item+=2; return array(0,">=");}
                $item++; return array(0,">");
            case '=':
                $item++; return array(0,"=");
            case '!':
                if ($rule[$item+1]=='=') { $item+=2; return array(0,"!=");}
                throw new Exception("Erreur in expr - incorrect operator '!'  found in ".$rule ." at " .$item);
            case '~':
                $item++; return array(0,"~");
            case '|':
                $item++; return array(1,"|");
            case '&':
                $item++; return array(1,"&");
            default	:
                throw new Exception("Erreur in expr - operator not found in ".$rule ." at " .$item);
        }
    }
    
    private function do_compare($val1,$val2,$comp,$negate)
    {
        switch ($comp){
            case '<':	$retVal= ($val1 < $val2); break;
            case '<=':	$retVal= ($val1 <= $val2); break;
            case '>':	$retVal= ($val1 > $val2); break;
            case '>=':	$retVal= ($val1 >= $val2); break;
            case '=':	$retVal= ($val1 == $val2); break;
            case '!=':	$retVal= ($val1 != $val2); break;
            case '~':	$retVal= (preg_match('/'.preg_replace('/"/','',$val2).'/',$val1)); break;
            case '|':	$retVal= ($val1 || $val2); break;
            case '&':	$retVal= ($val1 && $val2); break;
            default:  throw new Exception("Error in expression - unknown comp : ".$comp);
        }
        if ($negate === true) $retVal = ! $retVal; // Inverse result if negate before expression
        
        return $retVal;
    }
    
    /** Evaluation : makes token and evaluate.
     *	Public function for expressions testing
     *	accepts : < > = <= >= !=  (typec = 0)
     *	operators : & | (typec=1)
     *	with : integers/float  (type 0) or strings "" (type 1) or results (type 2)
     *   comparison int vs strings will return null (error)
     *	return : bool or null on error
     */
    public function evaluation($rule,&$item)
    {
        //echo "Evaluation of ".substr($rule,$item)."\n";
        $negate=$this->check_negate_first($rule, $item);
        // First element : number, string or ()
        list($type1,$val1) = $this->eval_getElement($rule,$item);
        //echo "Elmt1: ".$val1."/".$type1." : ".substr($rule,$item)."\n";
        
        if ($item==strlen($rule)) // If only element, return value, but only boolean
        {
            if ($type1 != 2) throw new Exception("Cannot use num/string as boolean : ".$rule);
            if ($negate === true) $val1= ! $val1;
            return $val1;
        }
        
        // Second element : operator
        list($typec,$comp) = $this->eval_getOper($rule,$item);
        //echo "Comp : ".$comp." : ".substr($rule,$item)."\n";
        
        // Third element : number, string or ()
        if ( $rule[$item] == '!') // starts with a ! so evaluate whats next
        {
            $item++;
            if ($typec != 1) throw new Exception("Mixing boolean and comparison : ".$rule);
            $val2= ! $this->evaluation($rule,$item);
            $type2=2; // result is a boolean
        }
        else
        {
            list($type2,$val2) = $this->eval_getElement($rule,$item);
        }
        //echo "Elmt2: ".$val2."/".$type2." : ".substr($rule,$item)."\n";
        
        if ($type1!=$type2)  // cannot compare different types
        {
            throw new Exception("Cannot compare string & number : ".$rule);
        }
        if ($typec==1 && $type1 !=2) // cannot use & or | with string/number
        {
            throw new Exception("Cannot use boolean operators with string & number : ".$rule);
        }
        
        $retVal = $this->do_compare($val1, $val2, $comp, $negate);
        
        if ($item==strlen($rule)) return $retVal; // End of string : return evaluation
        // check for logical operator :
        switch ($rule[$item])
        {
            case '|':	$item++; return ($retVal || $this->evaluation($rule,$item) );
            case '&':	$item++; return ($retVal && $this->evaluation($rule,$item) );
            
            default:  throw new Exception("Erreur in expr - garbadge at end of expression : ".$rule[$item]);
        }
    }
    
    /**
     * Get '*' or '**' and transform in [0-9]+ or .* in return string
     * @param string $oid OID in normal or regexp format. '*' will be escaped ('\*')
     * @return string correct regexp format
     */
    public function regexp_eval(string &$oid)
    {
        // ** replaced by .*
        $oidR=preg_replace('/\*\*/', '.*', $oid);
        // * replaced by [0-9]+
        $oidR=preg_replace('/\*/', '[0-9]+', $oidR);
        
        // replace * with \* in oid for preg_replace
        $oid=preg_replace('/\*/', '\*', $oid);
        
        $this->logging->log('Regexp eval : '.$oid.' / '.$oidR,DEBUG );
        
        return $oidR;
    }
    
    
    /** Evaluation rule (uses eval_* functions recursively)
     *	@param string $rule : rule ( _OID(.1.3.6.1.4.1.8072.2.3.2.1)=_OID(.1.3.6.1.2.1.1.3.0) )
     *  @param array $oidList : OIDs values to sustitute.
     *	@return bool : true : rule match, false : rule don't match , throw exception on error.
     */   
    public function eval_rule($rule,$oidList)
    {
        if ($rule==null || $rule == '') // Empty rule is always true
        {
            return true;
        }
        $matches=array();
        while (preg_match('/_OID\(([0-9\.\*]+)\)/',$rule,$matches) == 1)
        {
            $oid=$matches[1];
            $found=0;
            // Test and transform regexp
            $oidR = $this->regexp_eval($oid);
            
            foreach($oidList as $val)
            {
                if (preg_match("/^$oidR$/",$val->oid) == 1)
                {
                    if (!preg_match('/^-?[0-9]*\.?[0-9]+$/',$val->value))
                    { // If not a number, change " to ' and put " around it
                        $val->value=preg_replace('/"/',"'",$val->value);
                        $val->value='"'.$val->value.'"';
                    }
                    $rep=0;
                    $rule=preg_replace('/_OID\('.$oid.'\)/',$val->value,$rule,-1,$rep);
                    if ($rep==0)
                    {
                        $this->logging->log("Error in rule_eval",WARN,'');
                        return false;
                    }
                    $found=1;
                    break;
                }
            }
            if ($found==0)
            {	// OID not found : throw error
                throw new Exception('OID '.$oid.' not found in trap');
            }
        }
        $item=0;
        $rule=$this->eval_cleanup($rule);
        $this->logging->log('Rule after clenup: '.$rule,INFO );
        
        return  $this->evaluation($rule,$item);
    }
    
}