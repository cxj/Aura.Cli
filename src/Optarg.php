<?php
/**
 * 
 * This file is part of Aura for PHP.
 * 
 * @package Aura.Cli
 * 
 * @license http://opensource.org/licenses/bsd-license.php BSD
 * 
 */
namespace Aura\Cli;

use Aura\Cli\Exception;
use UnexpectedValueException;

/**
 * 
 * Parses command line option and argument values.
 * 
 * The new idea here is to pass Getopt separately, perhaps as Optarg, so that
 * we can parse the options and arguments separately from the context. This
 * allows a console to read different options from a command, and not get in
 * each others' ways.
 * 
 * @package Aura.Cli
 * 
 */
class Optarg
{
    /**
     * 
     * Definitions for recognized options.
     *      
     * @var array
     * 
     */
    protected $opt_defs = [];

    /**
     * 
     * Values for passed options.
     *      
     * @var array
     * 
     */
    protected $opts = [];
    
    /**
     * 
     * Names for sequential arguments.
     * 
     * @var array
     * 
     */
    protected $arg_defs = [];
    
    /**
     * 
     * Values for remaining arguments after loading options.
     * 
     * @var array
     * 
     */
    protected $args = [];

    /**
     * 
     * The incoming arguments, typically from $_SERVER['argv'].
     * 
     * @param array
     * 
     */
    protected $argv = [];

    protected $errors = [];
    
    /**
     * 
     * Set the getopt definitions.
     * 
     * @param array $defs The definitions. Each element is a short flag
     * character or long option name; two colons means an optional param, one
     * colon means a required param, no colon means no param is allowed.
     * (Cf. <http://php.net/getopt>.)
     * 
     * @return null
     * 
     */
    public function setOptDefs($defs)
    {
        $this->opt_defs = [];
        foreach ($defs as $key => $val) {
            
            $def = [
                'name'  => null,
                'param' => null,
            ];
            
            if (is_int($key)) {
                // 0 => 'f:'
                $key = $val;
                $def['name'] = rtrim($val, ':');
            } else {
                // 'f:' => 'foo'
                $def['name'] = $val;
            }
            
            // is a param optional/required/rejected?
            if (substr($key, -2) == '::') {
                $def['param'] = 'optional';
            } elseif (substr($key, -1) == ':') {
                $def['param'] = 'required';
            } else {
                $def['param'] = 'rejected';
            }
            
            // retain the definition
            $key = rtrim($key, ':');
            $this->opt_defs[$key] = $def;
        }
    }
    
    /**
     * 
     * Returns the option definitions.
     * 
     * @return array
     * 
     */
    public function getOptDefs()
    {
        return $this->opt_defs;
    }
    
    /**
     * 
     * Gets a single option definition.
     * 
     * When in strict mode, looking for an undefined option will thrown an
     * OptionNotDefined exception.  When not in strict mode:
     * 
     * - looking for an undefined short flag (e.g., 'u') returns
     *   `['name' => 'u', 'param' => 'rejected']`
     * 
     * - looking for an undefined long option (e.g., 'undef') returns
     *   `['name' => 'undef', 'param' => 'optional']`
     * 
     * @param string $key The definition key to look for.
     * 
     * @return array An option definition array with two keys, 'name' (the
     * option name) and 'param' (whether a param is rejected, required, or
     * optional).
     * 
     */
    public function getOptDef($key)
    {
        // is the option defined?
        if (isset($this->opt_defs[$key])) {
            return $this->opt_defs[$key];
        }
        
        // undefined; retain a message about it then deal with it
        if (strlen($key) == 1) {
            $opt = "-$key";
        } else {
            $opt = "--$key";
        }
        $this->errors[] = "The option '$opt' is not recognized.";
        
        // undefined short flags take no param
        if (strlen($key) == 1) {
            return ['name' => $key, 'param' => 'rejected'];
        }
        
        // undefined long options take an optional param
        return ['name' => $key, 'param' => 'optional'];
    }
    
    /**
     * 
     * Sets the names for sequential arguments.
     * 
     * @param array $arg_defs An array where element 0 is the name for
     * argument 0, element 1 for argument 1, etc.
     * 
     * @return null
     * 
     */
    public function setArgDefs(array $arg_defs)
    {
        $this->arg_defs = $arg_defs;
    }
    
    /**
     * 
     * Returns the names for sequential arguments.
     * 
     * @return array
     * 
     */
    public function getArgDefs()
    {
        return $this->arg_defs;
    }
    
    public function getErrors()
    {
        return $this->errors;
    }
    
    /**
     * 
     * Loads `$opts` and `$args` values from an argument array.
     * 
     * @param array $argv An argument array, typically from `$_SERVER['argv']`.
     * 
     * @return null
     * 
     */
    public function parse(array $argv)
    {
        // hold onto the argv source
        $this->argv = $argv;

        // reset option and argument values
        $this->errors = [];
        $this->opts = [];
        $this->args = [];
        
        // flag to say when we've reached the end of options
        $done = false;

        // shift each element from the top of the $argv source
        while ($this->argv) {

            // get the next argument
            $arg = array_shift($this->argv);

            // after a plain double-dash, all values are params (not options)
            if ($arg == '--') {
                $done = true;
                continue;
            }

            // if we're reached the end of options, just add to the params
            if ($done) {
                $this->args[] = $arg;
                continue;
            }

            // long option, short option, or numeric param?
            if (substr($arg, 0, 2) == '--') {
                $this->loadLong($arg);
            } elseif (substr($arg, 0, 1) == '-') {
                $this->loadShort($arg);
            } else {
                $this->args[] = $arg;
            }
        }
        
        // set the named arguments
        foreach ($this->arg_defs as $i => $name) {
            if (isset($this->args[$i])) {
                $this->args[$name] = $this->args[$i];
            }
        }
        
        // did parsing work without errors?
        return $this->errors ? false : true;
    }

    /**
     * 
     * Returns the option-values object.
     * 
     * @return Values
     * 
     */
    public function getOpts()
    {
        return $this->opts;
    }
    
    /**
     * 
     * Returns the argument-values object.
     * 
     * @return Values
     * 
     */
    public function getArgs()
    {
        return $this->args;
    }
    
    /**
     * 
     * Parses a long-form option.
     * 
     * @param string $key The `$argv` element, e.g. "--foo" or "--bar=baz".
     * 
     * @return null
     * 
     */
    protected function loadLong($key)
    {
        // take the leading "--" off the specification
        $key = substr($key, 2);
        
        // split the spec into name and value
        $pos = strpos($key, '=');
        if ($pos === false) {
            $val = null;
        } else {
            $val = substr($key, $pos + 1);
            $key = substr($key, 0, $pos);
        }

        // get the option definition
        $def = $this->getOptDef($key);

        // if param is required but not present, blow up
        if ($def['param'] == 'required' && trim($val) === '') {
            $this->errors[] = "The option '--$key' requires a parameter.";
            return;
        }

        // if params are rejected and one is present, blow up
        if ($def['param'] == 'rejected' && trim($val) !== '') {
            $this->errors[] = "The option '--$key' does not accept a parameter.";
            return;
        }

        // if param is not present, set to true
        if (trim($val) === '') {
            $val = true;
        }
        
        // retain the value, and done
        $this->setOpt($def, $val);
    }

    /**
     * 
     * Parses a short-form option (or cluster of options).
     * 
     * @param string $spec The `$argv` element, e.g. "-f" or "-fbz".
     * 
     * @return null
     * 
     */
    protected function loadShort($spec)
    {
        // if we have a string like "-abcd", process as a cluster
        if (strlen($spec) > 2) {
            return $this->loadShortCluster($spec);
        }

        // get the option character (after the first "-")
        $char = substr($spec, 1);

        // get the option object
        $def = $this->getOptDef($char);

        // if the option does not need a param, flag as true and move on
        if ($def['param'] == 'rejected') {
            $this->setOpt($def, true);
            return;
        }

        // the option was defined as needing a param (required or optional).
        // peek at the next element from $argv ...
        $value = reset($this->argv);

        // ... and see if it's a param. can be empty, too, which indicates
        // then end of the arguments.
        $is_param = ! empty($value) && substr($value, 0, 1) != '-';

        if (! $is_param && $def['param'] == 'optional') {
            // the next value is not a param, but a param is optional,
            // so flag the option as true and move on.
            $this->setOpt($def, true);
            return;
        }

        if (! $is_param && $def['param'] == 'required') {
            // the next value is not a param, but a param is required
            $this->errors[] = "The option '-$char' requires a parameter.";
            return;
        }

        // at this point, the value is a param, and it's optional or required.
        // pull it out of the arguments for real ...
        $value = array_shift($this->argv);

        // ... and set it.
        $this->setOpt($def, $value);
    }

    /**
     * 
     * Sets an option value; if an option value is set multiple times, it is
     * automatically converted to an array.
     * 
     * @param array $def The option definition.
     * 
     * @param mixed $value The option value.
     * 
     * @return null
     * 
     */
    protected function setOpt($def, $value)
    {
        $name = $def['name'];
        if (isset($this->opts[$name])) {
            // force to an array
            settype($this->opts[$name], 'array');
            $this->opts[$name][] = $value;
        } else {
            $this->opts[$name] = $value;
        }
    }
    
    /**
     * 
     * Parses a cluster of short options.
     * 
     * @param string $spec The short-option cluster (e.g. "-abcd").
     * 
     * @return null
     * 
     */
    protected function loadShortCluster($spec)
    {
        // drop the leading dash
        $spec = substr($spec, 1);

        // loop through each character in the cluster
        $k = strlen($spec);
        for ($i = 0; $i < $k; $i ++) {

            // get the right character from the cluster
            $char = $spec[$i];

            // get the option definition
            $def = $this->getOptDef($char);

            // can't process params in a cluster
            if ($def['param'] == 'required') {
                $this->errors[] = "The option '-$char' requires a parameter.";
                continue;
            }

            // otherwise, set the value as a flag
            $this->setOpt($def, true);
        }
    }
}
