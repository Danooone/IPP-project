<?php

namespace IPP\Student;

use IPP\Core\AbstractInterpreter;
use IPP\Core\Exception\XMLException;

/**
 * Main interpreter class
 */
class Interpreter extends AbstractInterpreter
{
    /**
     * @var array<int, array<int, array<int, array<int, string>>>>
     */
    private array $instructions; // $instructions[$index][0] == 'INSTRUCTION'; $instructions[$index][1] = [1 => [$type1, $operand1], 2 => [$type2, $operand2]...]
    /**
     * @var array<string, int>
     */
    private array $labels; // $labels['labelName'] == $instructionIndex

    /**
     * @var array<string, mixed>
     */
    private array $frameGlobal; // $frameGlobal['varName'] == $value
    /**
     * @var array<string, mixed>
     */
    private ?array $frameTemp; // $frameTemp['varName'] == $value; $frameTemp === null if not created
    /**
     * @var array<string, mixed>
     */
    private ?array $frameLocal; // $frameLocal['varName'] == $value; $frameLocal === null if not created

    private int $maxInstructionIndex;
    private int $framesInStack;
    /**
     * @var \SplStack<?array<string, mixed>>
     */
    private \SplStack $stackFrames; // local frame ($frameLocal) is not is $stackFrames
    /**
     * @var \SplStack<mixed>
     */
    private \SplStack $stackData;
    /**
     * @var \SplStack<int>
     */
    private \SplStack $stackCode;

    private \stdClass $NIL; // value for nil variable
    private \stdClass $UNASSIGNED; // value for unassigned variable

    const int OP_VAR = 1;
    const int OP_SYMB = 2;
    const int OP_LABEL = 3;
    const int OP_TYPE = 4;

    public function execute(): int
    {
        setlocale(LC_ALL, 'en_CZ.UTF-8');
        $this->NIL = new \stdClass(); // instance of empty class
        $this->UNASSIGNED = new \stdClass(); // another instance of empty class
        $this->readSourceFile();
        $this->createLabelMap();
        $code = $this->interpret();
        return $code;
    }

    private function readSourceFile(): void
    {
        // Clear instruction array
        $this->instructions = [];
        $this->maxInstructionIndex = 0;

        // Load XML document from file
        $dom = $this->source->getDOMDocument();
        if (is_null($dom->documentElement)) {
            throw new XMLException();
        }

        // Check root node and language attribute
        $attrLanguage = $dom->documentElement->attributes['language'] ?? null;
        if ($dom->documentElement->nodeName != 'program' || is_null($attrLanguage) || $attrLanguage->nodeValue != 'IPPcode24') {
            throw new XMLStructureException();
        }

        // Iterate instructions
        foreach ($dom->documentElement->childNodes as $nodeInstr) {
            if (!is_a($nodeInstr, 'DOMElement')) { continue; } // skip nodes of DOMText class
            // Check node name
            if ($nodeInstr->nodeName != 'instruction') {
                throw new XMLStructureException();
            }
            // Get and check order and opcode attributes
            $attrOrder = $nodeInstr->attributes['order'] ?? null;
            $attrOpcode = $nodeInstr->attributes['opcode'] ?? null;
            if (is_null($attrOrder) || is_null($attrOpcode)) {
                throw new XMLStructureException();
            }
            $order = (int)$attrOrder->nodeValue;
            $opcode = strtoupper($attrOpcode->nodeValue);
            if ($order < 1 || array_key_exists($order, $this->instructions) || $opcode === '') {
                throw new XMLStructureException();
            }

            // Iterate arguments (operands)
            $args = [];
            $argIndex = 0;
            foreach ($nodeInstr->childNodes as $nodeArg) {
                if (!is_a($nodeArg, 'DOMElement')) { continue; } // skip nodes of DOMText class
                // Check node name
                $argIndexStr = substr($nodeArg->nodeName, 3);
                $argIndex = (int)$argIndexStr;
                if (!str_starts_with($nodeArg->nodeName, 'arg') || !ctype_digit($argIndexStr) || $argIndex == 0 || array_key_exists($argIndex, $args)) {
                    throw new XMLStructureException();
                }
                // Get and check type attribute
                $attrType = $nodeArg->attributes['type'] ?? null;
                if (is_null($attrType) || $attrType->nodeValue === '') {
                    throw new XMLStructureException();
                }
                // Add arg to array
                $args[$argIndex] = [trim($attrType->nodeValue), trim((string)$nodeArg->nodeValue)];
            }

            // Add instruction to array
            $this->instructions[$order] = [$opcode, $args];
            $this->maxInstructionIndex = max($this->maxInstructionIndex, $order);
        }

        // Sort instructions by order
        ksort($this->instructions);
    }

    private function createLabelMap(): void
    {
        // Clear label array
        $this->labels = [];

        foreach ($this->instructions as $index => $value) {
            // Check all 'LABEL' instructions
            if ($value[0] == 'LABEL') {
                if (count($value[1]) != 1 || $value[1][1][0] != 'label' ||
                    array_key_exists($labelName = $value[1][1][1], $this->labels)) {
                    throw new SemanticException();
                }
                $this->checkName($labelName);
                $this->labels[$labelName] = $index; // add instruction order for label
            }
        }
    }

    private function checkName(string $name): void
    {
        if ($name === '' || ctype_digit($name[0])) {
            throw new OperandValueException();
        }
        foreach (str_split($name) as $ch) {
            if (!ctype_alnum($ch) && !str_contains('_-$&%*!?', $ch)) {
                echo "($ch)";
                throw new OperandValueException();
            }
        }
    }

    /**
     * @param array<int, array<int, string>> $ops
     * @param array<int, int> $types
     */
    private function checkOperands(array $ops, array $types): void
    {
        foreach ($ops as $i => $op) {
            if (!array_key_exists($i-1, $types)) {
                throw new XMLStructureException();
            }
            switch ($types[$i-1]) {
                case self::OP_VAR:
                    if ($op[0] != 'var') {
                        throw new OperandTypeException();
                    }
                    break;
                case self::OP_SYMB:
                    if ($op[0] != 'var' && $op[0] != 'int' && $op[0] != 'string' && $op[0] != 'bool' && $op[0] != 'nil') {
                        throw new OperandTypeException();
                    }
                    break;
                case self::OP_LABEL:
                    if ($op[0] != 'label') {
                        throw new OperandTypeException();
                    }
                    $this->checkName($op[1]);
                    break;
                case self::OP_TYPE:
                    if ($op[0] != 'type') {
                        throw new XMLStructureException();
                    }
                    break;
            }
        }
    }

    /**
     * @description Returns reference to variable. Check definition if $check = true
     */
    private function &getVar(string $name, bool $check = false): mixed
    {
        $frameVar = explode('@', $name, 2);
        if (count($frameVar) != 2) {
            throw new OperandValueException();
        }
        $frameType = $frameVar[0];
        $varName = $frameVar[1];
        $this->checkName($varName);
        switch ($frameType) {
            case 'GF':
                $frame = &$this->frameGlobal;
                break;
            case 'LF':
                $frame = &$this->frameLocal;
                break;
            case 'TF':
                $frame = &$this->frameTemp;
                break;
            default:
                throw new XMLStructureException();
        }
        if (is_null($frame)) {
            throw new FrameAccessException();
        }
        if (!array_key_exists($varName, $frame)) {
            throw new VariableAccessException();
        }
        if ($check && $frame[$varName] === $this->UNASSIGNED) {
            throw new ValueException();
        }
        return $frame[$varName];
    }

    /**
     * @description Get value from variable or constant
     * @param array<int, string> $op
     */
    private function getValue(array $op, bool $checkVar = true): mixed
    {
        $type = $op[0];
        $value = $op[1];
        if ($type == 'var') {
            return $this->getVar($value, $checkVar);
        }
        $fail = false;
        switch ($type) {
            case 'int':
                if (str_starts_with(strtolower($value), '0x')) {
                    $v = substr($value, 2);
                    $fail = !ctype_xdigit($v);
                    $value = hexdec($v);
                } elseif (str_starts_with(strtolower($value), '-0x')) {
                    $v = substr($value, 3);
                    $fail = !ctype_xdigit($v);
                    $value = hexdec('-'.$v);
                } elseif (str_starts_with(strtolower($value), '0o')) {
                    $v = substr($value, 2);
                    $fail = !preg_match('/^[0-7]+$/', $v);
                    $value = octdec($v);
                } elseif (str_starts_with(strtolower($value), '-0o')) {
                    $v = substr($value, 3);
                    $fail = !preg_match('/^[0-7]+$/', $v);
                    $value = octdec('-'.$v);
                } else {
                    $v = $value;
                    if (str_starts_with($v, '-')) {
                        $v = substr($v, 1);
                    }
                    $fail = !ctype_digit($v);
                    $value = (int)$value;
                }
                break;
            case 'string':
                $v = $value;
                $value = '';
                for ($i = 0; $i < strlen($v); $i++) {
                    if ($v[$i] == '\\') {
                        $code = substr($v, $i+1, 3);
                        if ($i+3 >= strlen($v) || !ctype_digit($code)) {
                            $fail = true;
                            break;
                        }
                        $value .= chr((int)$code);
                        $i += 3;
                    } else {
                        $value .= $v[$i];
                    }
                }
                break;
            case 'bool':
                switch ($value) {
                    case 'true':
                        $value = true;
                        break;
                    case 'false':
                        $value = false;
                        break;
                    default:
                        $fail = true;
                }
                break;
            case 'type':
                break;
            case 'nil':
                $fail = ($value != 'nil');
                $value = $this->NIL;
                break;
            default:
                $fail = true;
        }
        if ($fail) {
            throw new ValueException();
        }
        return $value;
    }

    /**
     * @description Get integer value from variable or constant
     * @param array<int, string> $op
     */
    private function getInt(array $op): int
    {
        $value = $this->getValue($op);
        if (gettype($value) != 'integer') {
            throw new OperandTypeException();
        }
        return $value;
    }

    /**
     * @description Get string value from variable or constant
     * @param array<int, string> $op
     */
    private function getString(array $op): string
    {
        $value = $this->getValue($op);
        if (gettype($value) != 'string') {
            throw new OperandTypeException();
        }
        return $value;
    }

    /**
     * @description Get boolean value from variable or constant
     * @param array<int, string> $op
     */
    private function getBool(array $op): bool
    {
        $value = $this->getValue($op);
        if (gettype($value) != 'boolean') {
            throw new OperandTypeException();
        }
        return $value;
    }

    /**
     * @param ?array<string, mixed> $frame
     */
    private function dumpFrame(string $prefix, ?array $frame): void
    {
        if (is_null($frame)) {
            $this->stderr->writeString("--- frame is not created\n");
            return;
        }
        if (count($frame) == 0) {
            $this->stderr->writeString("--- frame doesn't contain variables\n");
            return;
        }
        foreach ($frame as $var => $value) {
            $this->stderr->writeString("--- name=\"$prefix@$var\": type=");
            switch (gettype($value)) {
                case 'integer':
                    $this->stderr->writeString('int, value=');
                    $this->stderr->writeInt($value);
                    break;
                case 'string':
                    $this->stderr->writeString('string, value="');
                    $value = str_replace("\n", '\n', $value);
                    $this->stderr->writeString("$value");
                    $this->stderr->writeString('"');
                    break;
                case 'boolean':
                    $this->stderr->writeString('bool, value=');
                    $this->stderr->writeBool($value);
                    break;
                case 'object':
                    if ($value === $this->NIL) {
                        $this->stderr->writeString('nil, value=nil');
                    } elseif ($value === $this->UNASSIGNED) {
                        $this->stderr->writeString('unassigned');
                    } else {
                        $this->stderr->writeString('UNEXPECTED(object/class="'.get_class($value).'")');
                    }
                    break;
                default:
                    $this->stderr->writeString('UNEXPECTED('.gettype($value).')');
            }
            $this->stderr->writeString("\n");
        }
    }

    private function interpret(): int
    {
        // Clear frames and stacks
        $this->frameGlobal = [];
        $this->frameTemp = null;
        $this->frameLocal = null;
        $this->framesInStack = 0;
        $this->stackFrames = new \SplStack();
        $this->stackData = new \SplStack();
        $this->stackCode = new \SplStack();

        $ip = 1; // instruction pointer
        $ic = 0; // instruction counter
        while (true) {
            //$this->stderr->writeString("[$ip]"); // display instruction index
            if ($ip > $this->maxInstructionIndex) {
                return 0;
            }
            $ip++;
            if (!array_key_exists($ip-1, $this->instructions)) {
                continue;
            }
            $instruction = $this->instructions[$ip-1];
            $opcode = $instruction[0];
            $ops = $instruction[1];
            $ic++;
            switch ($opcode) {
                case 'MOVE':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $var = $this->getValue($ops[2]);
                    break;
                case 'CREATEFRAME':
                    $this->checkOperands($ops, []);
                    $this->frameTemp = [];
                    break;
                case 'PUSHFRAME':
                    $this->checkOperands($ops, []);
                    if ($this->frameTemp === null) {
                        throw new FrameAccessException();
                    }
                    if ($this->framesInStack > 0) {
                        $this->stackFrames->push($this->frameLocal);
                    }
                    $this->frameLocal = $this->frameTemp;
                    $this->frameTemp = null;
                    $this->framesInStack++;
                    break;
                case 'POPFRAME':
                    $this->checkOperands($ops, []);
                    if ($this->framesInStack == 0) {
                        throw new FrameAccessException();
                    }
                    $this->frameTemp = $this->frameLocal;
                    if (!$this->stackFrames->isEmpty()) {
                        $this->frameLocal = $this->stackFrames->pop();
                    } else {
                        $this->frameLocal = null;
                    }
                    $this->framesInStack--;
                    break;
                case 'DEFVAR':
                    $this->checkOperands($ops, [self::OP_VAR]);
                    $name = $ops[1][1];
                    $stackVar = explode('@', $name, 2);
                    if (count($stackVar) != 2) {
                        throw new OperandValueException();
                    }
                    $stackType = $stackVar[0];
                    $varName = $stackVar[1];
                    $this->checkName($varName);
                    switch ($stackType) {
                        case 'GF':
                            $frame = &$this->frameGlobal;
                            break;
                        case 'LF':
                            $frame = &$this->frameLocal;
                            break;
                        case 'TF':
                            $frame = &$this->frameTemp;
                            break;
                        default:
                            throw new OperandValueException();
                    }
                    if (is_null($frame)) {
                        throw new FrameAccessException();
                    }
                    if (array_key_exists($varName, $frame)) {
                        throw new SemanticException();
                    }
                    $frame[$varName] = $this->UNASSIGNED;
                    break;
                case 'CALL':
                    $this->checkOperands($ops, [self::OP_LABEL]);
                    $name = $ops[1][1];
                    $this->checkName($name);
                    if (!array_key_exists($name, $this->labels)) {
                        throw new SemanticException();
                    }
                    $this->stackCode->push($ip);
                    $ip = $this->labels[$name];
                    break;
                case 'RETURN':
                    $this->checkOperands($ops, []);
                    if ($this->stackCode->isEmpty()) {
                        throw new ValueException();
                    }
                    $ip = $this->stackCode->pop();
                    break;
                case 'PUSHS':
                    $this->checkOperands($ops, [self::OP_SYMB]);
                    $this->stackData->push($this->getValue($ops[1]));
                    break;
                case 'POPS':
                    $this->checkOperands($ops, [self::OP_VAR]);
                    $var = &$this->getVar($ops[1][1]);
                    if ($this->stackData->isEmpty()) {
                        throw new ValueException();
                    }
                    $var = $this->stackData->pop();
                    break;
                case 'ADD':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $var = $this->getInt($ops[2]) + $this->getInt($ops[3]);
                    break;
                case 'SUB':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $var = $this->getInt($ops[2]) - $this->getInt($ops[3]);
                    break;
                case 'MUL':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $var = $this->getInt($ops[2]) * $this->getInt($ops[3]);
                    break;
                case 'IDIV':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $a = $this->getInt($ops[2]);
                    $b = $this->getInt($ops[3]);
                    if ($b == 0) {
                        throw new OperandValueException();
                    }
                    $var = intdiv($a, $b);
                    break;
                case 'LT':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $a = $this->getValue($ops[2]);
                    $b = $this->getValue($ops[3]);
                    if (gettype($a) != gettype($b) || $a === $this->NIL || $b === $this->NIL) {
                        throw new OperandTypeException();
                    }
                    $var = $a < $b;
                    break;
                case 'GT':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $a = $this->getValue($ops[2]);
                    $b = $this->getValue($ops[3]);
                    if (gettype($a) != gettype($b) || $a === $this->NIL || $b === $this->NIL) {
                        throw new OperandTypeException();
                    }
                    $var = $a > $b;
                    break;
                case 'EQ':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $a = $this->getValue($ops[2]);
                    $b = $this->getValue($ops[3]);
                    if (gettype($a) != gettype($b) && $a !== $this->NIL && $b !== $this->NIL) {
                        throw new OperandTypeException();
                    }
                    $var = $a === $b;
                    break;
                case 'AND':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $var2 = $this->getBool($ops[2]);
                    $var3 = $this->getBool($ops[3]);
                    $var = $var2 && $var3;
                    break;
                case 'OR':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $var2 = $this->getBool($ops[2]);
                    $var3 = $this->getBool($ops[3]);
                    $var = $var2 || $var3;
                    break;
                case 'NOT':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $var = !$this->getBool($ops[2]);
                    break;
                case 'INT2CHAR':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $code = $this->getInt($ops[2]);
                    $ch = chr($this->getInt($ops[2]));
                    if (ord($ch) !== $code) {
                        throw new StringOperationException();
                    }
                    $var = $ch;
                    break;
                case 'STRI2INT':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $str = $this->getString($ops[2]);
                    $pos = $this->getInt($ops[3]);
                    if ($pos < 0 || $pos > strlen($str)) {
                        throw new StringOperationException();
                    }
                    $var = ord($str[$pos]);
                    break;
                case 'READ':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_TYPE]);
                    $var = &$this->getVar($ops[1][1]);
                    $type = $this->getString($ops[2]);
                    switch ($type) {
                        case 'int':
                            $value = $this->input->readInt();
                            break;
                        case 'string':
                            $value = $this->input->readString();
                            break;
                        case 'bool':
                            $value = $this->input->readBool();
                            break;
                        default:
                            throw new OperandValueException();
                    }
                    if (is_null($value)) {
                        $value = $this->NIL;
                    }
                    $var = $value;
                    break;
                case 'WRITE':
                    $this->checkOperands($ops, [self::OP_SYMB]);
                    $value = $this->getValue($ops[1]);
                    switch (gettype($value)) {
                        case 'integer':
                            $this->stdout->writeInt($value);
                            break;
                        case 'string':
                            $this->stdout->writeString($value);
                            break;
                        case 'boolean':
                            $this->stdout->writeBool($value);
                            break;
                    }
                    break;
                case 'CONCAT':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $var = $this->getString($ops[2]) . $this->getString($ops[3]);
                    break;
                case 'STRLEN':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $var = strlen($this->getString($ops[2]));
                    break;
                case 'GETCHAR':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $str = $this->getString($ops[2]);
                    $pos = $this->getInt($ops[3]);
                    if ($pos < 0 || $pos > strlen($str)) {
                        throw new StringOperationException();
                    }
                    $var = $str[$pos];
                    break;
                case 'SETCHAR':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $pos = $this->getInt($ops[2]);
                    $str = $this->getString($ops[3]);
                    if (gettype($var) != 'string' || $pos < 0 || $pos > strlen($var) || strlen($str) == 0) {
                        throw new StringOperationException();
                    }
                    $var[$pos] = $str[0];
                    break;
                case 'TYPE':
                    $this->checkOperands($ops, [self::OP_VAR, self::OP_SYMB]);
                    $var = &$this->getVar($ops[1][1]);
                    $value = $this->getValue($ops[2], false);
                    switch (gettype($value)) {
                        case 'integer':
                            $var = 'int';
                            break;
                        case 'string':
                            $var = 'string';
                            break;
                        case 'boolean':
                            $var = 'bool';
                            break;
                        case 'object':
                            if ($value === $this->NIL) {
                                $var = 'nil';
                            } else {
                                $var = '';
                            }
                    }
                    break;
                case 'LABEL':
                    $this->checkOperands($ops, [self::OP_LABEL]);
                    // already implemented in createLabelMap
                    break;
                case 'JUMP':
                    $this->checkOperands($ops, [self::OP_LABEL]);
                    $name = $ops[1][1];
                    $this->checkName($name);
                    if (!array_key_exists($name, $this->labels)) {
                        throw new SemanticException();
                    }
                    $ip = $this->labels[$name];
                    break;
                case 'JUMPIFEQ':
                    $this->checkOperands($ops, [self::OP_LABEL, self::OP_SYMB, self::OP_SYMB]);
                    $name = $ops[1][1];
                    $a = $this->getValue($ops[2]);
                    $b = $this->getValue($ops[3]);
                    $this->checkName($name);
                    if (!array_key_exists($name, $this->labels)) {
                        throw new SemanticException();
                    }
                    if (gettype($a) != gettype($b) && $a !== $this->NIL && $b !== $this->NIL) {
                        throw new OperandTypeException();
                    }
                    if ($a === $b) {
                        $ip = $this->labels[$name];
                    }
                    break;
                case 'JUMPIFNEQ':
                    $this->checkOperands($ops, [self::OP_LABEL, self::OP_SYMB, self::OP_SYMB]);
                    $name = $ops[1][1];
                    $a = $this->getValue($ops[2]);
                    $b = $this->getValue($ops[3]);
                    $this->checkName($name);
                    if (!array_key_exists($name, $this->labels)) {
                        throw new SemanticException();
                    }
                    if (gettype($a) != gettype($b) && $a !== $this->NIL && $b !== $this->NIL) {
                        throw new OperandTypeException();
                    }
                    if ($a !== $b) {
                        $ip = $this->labels[$name];
                    }
                    break;
                case 'EXIT':
                    $this->checkOperands($ops, [self::OP_SYMB]);
                    $code = $this->getInt($ops[1]);
                    if ($code < 0 || $code > 9) {
                        throw new OperandValueException();
                    }
                    return $code;
                case 'DPRINT':
                    $this->checkOperands($ops, [self::OP_SYMB]);
                    $value = $this->getValue($ops[1]);
                    switch (gettype($value)) {
                        case 'integer':
                            $this->stderr->writeInt($value);
                            break;
                        case 'string':
                            $this->stderr->writeString($value);
                            break;
                        case 'boolean':
                            $this->stderr->writeBool($value);
                            break;
                    }
                    break;
                case 'BREAK':
                    $this->checkOperands($ops, []);
                    $this->stderr->writeString("DEBUG INFO:\n");
                    $this->stderr->writeString("- IP (next instruction index) = $ip, IC (processed instruction counter) = $ic\n");
                    $this->stderr->writeString("- Frames in frame stack: $this->framesInStack\n");
                    $this->stderr->writeString("- Values in data stack: {$this->stackData->count()}\n");
                    $this->stderr->writeString("- Values in code stack: {$this->stackCode->count()}\n");
                    $this->stderr->writeString("- Global frame variables:\n");
                    $this->dumpFrame('GF', $this->frameGlobal);
                    $this->stderr->writeString("- Local frame variables:\n");
                    $this->dumpFrame('LF', $this->frameLocal);
                    $this->stderr->writeString("- Temp frame variables:\n");
                    $this->dumpFrame('TF', $this->frameTemp);
                    //return 0; // uncomment if execution must be broken
                default:
                    throw new XMLStructureException();
            }
        }
    }
}
