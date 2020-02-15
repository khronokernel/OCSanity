<?php
class Rules {
    /** @var array */
    public $rule = [];

    function __construct(string $filename) {
        if(!file_exists($filename)) {
            throw new \Exception('File not found');
        }
        $rls = file($filename);
        $group = null;
        $vars = $rule = [];
        foreach($rls as $line) {
            switch($line[0]) {
                case '#': case "\n": break;
                case '=': echo substr($line,1); break;
                case ' ':
                    if(empty($group)) throw new \Exception("Rule $line must belong to a group");
                    if(empty($section)) {
                        if(empty(trim($line))) break;
                        $rule[$group]['top'][] = Rule::make(trim($line), $vars);
                    } else {
                        if(empty(trim($line))) break;
                        $rule[$group][$section][] = Rule::make(trim($line), $vars);
                    }
                    break;

                case ':':
                    if(empty($group)) throw new \Exception("Section $line must belong to a group");
                    $section = trim($line);
                    $rule[$group][$section] = [];
                    break;

                case '$':
                    $vars[] = trim($line);
                    break;

                default:
                    $group = trim($line);
                    $section = null;
                    $rule[$group] = [];
                    break;
            }
        }
        $this->rule = $rule;
    }

    static function getList(string $path) {
        $ret = [];
        $dir = new DirectoryIterator($path);
        foreach ($dir as $fileinfo) {
            if($fileinfo->getExtension() === 'lst') {
                $fn = $fileinfo->getFilename();
                $fo = $fileinfo->openFile('r');
                $short = trim($fo->fgets(),"# \r\n");
                $long = trim($fo->fgets(),"# \r\n");
                $ret[substr($fn,0,-4)] = ['short'=>$short, 'long'=>$long];
            }
        }
        uasort($ret, function($a,$b) { return $a['short']<=>$b['short']; });
        return $ret;
    }
}

class Rule {
    public $title = '';

    static function make (string $line, array $varstrs) {
        @[$rulestr,$msgstr] = preg_split('@"[^"]*"(*SKIP)(*F)|\s+@', $line);
        @[$msgtrue,$msgfalse] = preg_split('@"[^"]*"(*SKIP)(*F)|:@', $msgstr);
        if(!preg_match('@"[^"]*"(*SKIP)(*F)|(==|!=|=|<|>|~=)@', $rulestr, $match)) {
            throw new \Exception("Syntax error on rule: $rulestr");
        }
        $op = $match[1];
        [$left,$right] = preg_split('@"[^"]*"(*SKIP)(*F)|(==|!=|=|<|>|~=)@', $rulestr);
        $vars = [];
        foreach($varstrs as $var) {
            if(strstr($var, '=') === false) {
                throw new \Exception("Syntax error on variable assign $var");
            }
            [$name,$val] = explode('=', $var, 2);
            $vars['{'.$name.'}'] = $val;
        }

        if($left == 'count') {
            return new CountRule($op, $right, $msgtrue, $msgfalse, $vars);
        }

        if($left[0] == '[') {
            return new AttrValueRule($op, $left, $right, $msgtrue, $msgfalse, $vars);
        }
        return new SettingRule($op, $left, $right, $msgtrue, $msgfalse, $vars);
    }
}

class CountRule extends Rule {
    private $op;
    private $right;
    private $msgtrue;
    private $msgfalse;
    private $vars;

    function __construct(string $op, string $right, ?string $msgtrue, ?string $msgfalse, array $vars) {
        $this->op = $op;
        $this->right = $right;
        $this->msgtrue = $msgtrue;
        $this->msgfalse = $msgfalse;
        $this->vars = $vars;
        $this->title = '';
    }

    public function exec($arg, $unused_check_missing=true):array {
        $count = count($arg);
        $vars = $this->vars + [ '{$count}' => $count ];
        $msgtrue = strtr((string)$this->msgtrue, $vars);
        $msgfalse = strtr((string)$this->msgfalse, $vars);

        switch($this->op) {
            case '==': return ($count==$this->right) ? [$msgtrue]:[$msgfalse];
            case '!=': return ($count!=$this->right) ? [$msgtrue]:[$msgfalse];
            case '<':  return ($count<$this->right)  ? [$msgtrue]:[$msgfalse];
            case '>':  return ($count>$this->right)  ? [$msgtrue]:[$msgfalse];
            default:
                throw new \Exception("Invalid operator in count expression");
        }
    }
}

class AttrValueRule extends Rule {
    private $op;
    private $left;
    private $right;
    private $msgtrue;
    private $msgfalse;
    private $vars;

    function __construct(string $op, string $left, string $right, ?string $msgtrue, ?string $msgfalse, array $vars) {
        $this->op = $op;
        $this->left = substr($left,1,-1);
        $this->msgtrue = $msgtrue;
        $this->msgfalse = $msgfalse;
        $this->vars = $vars;
        $this->title = '';
        if(strtolower($right)=='yes') $this->right = true;
        else if(strtolower($right)=='no') $this->right = false;
        else $this->right = trim($right,'"');
    }

    public function exec($arg, $unused_check_missing=true):array {
        $vars = $this->vars;
        $ret = [];
        $lop = null;

        // Special case, * matches all remaining attributes that haven't matched a previous rule
        if($this->op == '==' && $this->right === '*') {
            foreach($arg as $key=>$v) {
                foreach($v as $kk=>$vv) $vars = array_merge($vars, [ '{$'.$kk.'}' => is_bool($vv) ? ($vv?'Yes':'No') : $vv ]);
                $msgtrue = strtr((string)$this->msgtrue, $vars);
                $ret[$key+1] = $msgtrue;
            }
            return $ret;
        }

        $lookfor = [$this->right];

        if(preg_match('@"[^"]*"(*SKIP)(*F)|\|@', $this->right)) {
            $lookfor = preg_split('@"[^"]*"(*SKIP)(*F)|\|@', $this->right);
            $lop = '|';
        }
        if(preg_match('@"[^"]*"(*SKIP)(*F)|&@', $this->right)) {
            if($lop) {
                throw new \Exception("Mixing | and & in a single expression is currently not supported");
            }
            $lookfor = preg_split('@"[^"]*"(*SKIP)(*F)|&@', $this->right);
            $lop = '&';
        }

        $found = false;
        $found_count = $fkey = 0;
        $fv = [];

        foreach($arg as $key=>$v) {
            foreach($lookfor as $look) {
                if($this->op == '~=') {
                    if(preg_match('@'.$look.'@', $v[$this->left])) { $found_count++; $fkey = $key; $fv = $v; }
                } else {
                    if(!$lop || $lop=='|') {
                        if($v[$this->left] === $look) { $found_count++; $fkey = $key; $fv = $v; }
                    } else if($lop=='&') {
                        if($v[$this->left] === $look) { $found_count++; $fkey = $key; $fv = $v; }
                    }
                }
            }
        }
        if($lop=='&' && $found_count >= count($lookfor)) $found = true;
        else if((!$lop || $lop=='|') && $found_count) $found = true;

        foreach($fv as $kk=>$vv) $vars = array_merge($vars, [ '{$'.$kk.'}' => is_bool($vv) ? ($vv?'Yes':'No') : $vv ]);
        $msgtrue = strtr((string)$this->msgtrue, $vars);
        $msgfalse = strtr((string)$this->msgfalse, $vars);

        if($this->op == '!=') {
            if(!$found) $ret = [$msgtrue];
            else $ret[$fkey+1] = $msgfalse;  // return with index to remove match from list
        } else {
            if($found) $ret[$fkey+1] = $msgtrue;   // return with index to remove match from list
            else $ret = [$msgfalse];
        }

        return $ret;
    }
}

class SettingRule extends Rule {
    private $op = "";
    private $left = "";
    private $right = "";
    private $msgtrue = "";
    private $msgfalse = "";
    private $vars = [];

    function __construct(string $op, string $left, string $right, ?string $msgtrue, ?string $msgfalse, array $vars) {
        $this->op = $op;
        $this->left = $left;
        if($op != '=' && $op != '~=') $this->title = $left;
        $this->msgtrue = $msgtrue;
        $this->msgfalse = $msgfalse;
        $this->vars = $vars;
        if(strtolower($right)=='yes') $this->right = true;
        else if(strtolower($right)=='no') $this->right = false;
        else {
            $tmp = trim($right, '"');
            if(is_numeric($tmp) && ($tmp[0]!=0 || strlen($tmp)==1)) {
                if(strstr($tmp, '.') === false) $tmp = (int)$tmp;
                else $tmp = (float)$tmp;
            }
            $this->right = $tmp;
        }
    }

    public function exec($arg, $check_missing=true):array {
        $vars = $this->vars;
        $ret = [];
        $msgtrue = $msgfalse = "";

        foreach($arg as $key=>$val) {
            if($this->op == '=' || $this->op == '~=') {
                if($this->left === $key) {
                    // Look for modifiers and apply
                    @[$type,$v] = preg_split('@"[^"]*"(*SKIP)(*F)|:@', $this->right);
                    if(!empty($v)) {
                        switch($type) {
                            case 'bin':
                                $val = bin2hex($val);
                                $right = $v;
                                break;
                            default:
                                $right = $this->right;
                                break;
                        }
                    } else {
                        $right = $this->right;
                    }

                    // Populate local symbol table
                    $vars = $this->vars + [ '{$setting}' => $key, '{$value}' => $val ];
                    if(!empty($this->msgtrue)) {
                        $msgtrue = strtr($this->msgtrue, $vars);
                    }
                    if(!empty($this->msgfalse)) {
                        $msgfalse = strtr($this->msgfalse, $vars);
                    }

                    // Apply condition
                    if(($this->op == '=' && $right === $val) || ($this->op == '~=' && preg_match('@'.$right.'@', $val))) {
                        $ret[$key] = empty($msgtrue) ? " **$key**" : $msgtrue;
                    } else {
                        if(empty($msgfalse)) {
                            $ret[$key] = "-**$key** should normally be ";
                            if($right === true) $ret[$key].="**Yes**";
                            else if($right === false) $ret[$key].="**No**";
                            else if($right === "") $ret[$key].="**<blank>**";
                            else $ret[$key].="*{$right}*";
                        } else {
                            $ret[$key] = $msgfalse;
                        }
                    }
                }
            } else if($this->left === $key) {
                if(!is_array($val)) {
                    return ["!**$key** is missing"];
                }
                //
                // Special case, * matches all remaining attributes that haven't matched a previous rule
                if($this->op == '==' && $this->right === '*') {
                    foreach($val as $k=>$v) {
                        $vars = $this->vars + [ '{$setting}' => $key, '{$value}' => $v ];
                        $msgtrue = strtr($this->msgtrue, $vars);
                        $ret[":$key:$k"] = $msgtrue;
                    }
                    return $ret;
                }

                $found = false;
                $found_count = 0;
                $fv = [];
                $lop = null;
                $lookfor = [$this->right];

                if(preg_match('@"[^"]*"(*SKIP)(*F)|\|@', $this->right)) {
                    $lookfor = preg_split('@"[^"]*"(*SKIP)(*F)|\|@', $this->right);
                    $lop = '|';
                }
                if(preg_match('@"[^"]*"(*SKIP)(*F)|&@', $this->right)) {
                    if($lop) {
                        throw new \Exception("Mixing | and & in a single expression is currently not supported");
                    }
                    $lookfor = preg_split('@"[^"]*"(*SKIP)(*F)|&@', $this->right);
                    $lop = '&';
                }

                $found = false;
                $found_count = $fkey = 0;
                $fv = '';

                foreach($val as $k=>$v) {
                    foreach($lookfor as $look) {
                        if(!$lop || $lop=='|') {
                            if($v === $look) { $found_count++; $fkey = $k; $fv = $v; }
                        } else if($lop=='&') {
                            if($v === $look) { $found_count++; $fkey = $k; $fv = $v; }
                        }
                    }
                }
                if($lop=='&' && $found_count >= count($lookfor)) $found = true;
                else if((!$lop || $lop=='|') && $found_count) $found = true;

                $vars = $this->vars + [ '{$setting}' => $key, '{$value}' => $fv ];
                $msgtrue = strtr($this->msgtrue, $vars);
                $msgfalse = strtr($this->msgfalse, $vars);

                if($this->op == '!=') {
                    if(!$found) $ret = [$msgtrue];
                    else $ret[":$key:$fkey"] = $msgfalse;  // return with full encoded index to remove match from list
                } else {
                    if($found) $ret[":$key:$fkey"] = $msgtrue;   // return with full encoded index to remove match from list
                    else $ret = [$msgfalse];
                }
            }
        }

        if(empty($this->msgtrue) && $check_missing && ($this->op == '=' || $this->op == '~=') && empty($ret)) {
            $right = $this->right;
            if($right === true) $right="Yes";
            else if($right === false) $right = "No";
            else if($right === "") $right = "<blank>";

            $vars = $this->vars + [ '{$setting}' => $this->left, '{$value}' => $right ];
            // Overriding user-supplied msgfalse in this missing setting case
            $msgfalse = "-**{$this->left}** is missing. Normally set to **{$right}**";
            $ret = [$msgfalse];
        }
        return $ret;
    }
}
