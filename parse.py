import sys, locale, re
import xml.etree.ElementTree as ET

""" CONSTANTS """

# Exit codes
SUCCESS         = 0;
PARAMETER_ERROR = 10;
INPUT_ERROR     = 11;
OUTPUT_ERROR    = 12;
INVALID_HEADER  = 21;
INVALID_OPCODE  = 22;
SYNTAX_ERROR    = 23;
INTERNAL_ERROR  = 99;

# Write messages to stderr
stderrOutput = True


""" PRINT TO STDERR """
def eprint(*elems):
    if stderrOutput:
        print(*elems, file=sys.stderr)


""" PARSER CLASS """
class Parser:

    """ Initialize variables and locale """
    def __init__(self):
        # Languige ID (header)
        self.langId = ".IPPcode24".upper()

        # Instruction info
        self.OP_VAR = 1
        self.OP_SYMB = 2
        self.OP_LABEL = 3
        self.OP_TYPE = 4
        self.Instructions = {
            "MOVE"        : [self.OP_VAR, self.OP_SYMB],
            "CREATEFRAME" : [],
            "PUSHFRAME"   : [],
            "POPFRAME"    : [],
            "DEFVAR"      : [self.OP_VAR],
            "CALL"        : [self.OP_LABEL],
            "RETURN"      : [],
            "PUSHS"       : [self.OP_SYMB],
            "POPS"        : [self.OP_VAR],
            "ADD"         : [self.OP_VAR, self.OP_SYMB, self.OP_SYMB],
            "SUB"         : [self.OP_VAR, self.OP_SYMB, self.OP_SYMB],
            "MUL"         : [self.OP_VAR, self.OP_SYMB, self.OP_SYMB],
            "IDIV"        : [self.OP_VAR, self.OP_SYMB, self.OP_SYMB],
            "LT"          : [self.OP_VAR, self.OP_SYMB, self.OP_SYMB],
            "GT"          : [self.OP_VAR, self.OP_SYMB, self.OP_SYMB],
            "EQ"          : [self.OP_VAR, self.OP_SYMB, self.OP_SYMB],
            "AND"         : [self.OP_VAR, self.OP_SYMB, self.OP_SYMB],
            "OR"          : [self.OP_VAR, self.OP_SYMB, self.OP_SYMB],
            "NOT"         : [self.OP_VAR, self.OP_SYMB],
            "INT2CHAR"    : [self.OP_VAR, self.OP_SYMB],
            "STRI2INT"    : [self.OP_VAR, self.OP_SYMB, self.OP_SYMB],
            "READ"        : [self.OP_VAR, self.OP_TYPE],
            "WRITE"       : [self.OP_SYMB],
            "CONCAT"      : [self.OP_VAR, self.OP_SYMB, self.OP_SYMB],
            "STRLEN"      : [self.OP_VAR, self.OP_SYMB],
            "GETCHAR"     : [self.OP_VAR, self.OP_SYMB, self.OP_SYMB],
            "SETCHAR"     : [self.OP_VAR, self.OP_SYMB, self.OP_SYMB],
            "TYPE"        : [self.OP_VAR, self.OP_SYMB],
            "LABEL"       : [self.OP_LABEL],
            "JUMP"        : [self.OP_LABEL],
            "JUMPIFEQ"    : [self.OP_LABEL, self.OP_SYMB, self.OP_SYMB],
            "JUMPIFNEQ"   : [self.OP_LABEL, self.OP_SYMB, self.OP_SYMB],
            "EXIT"        : [self.OP_SYMB],
            "DPRINT"      : [self.OP_SYMB],
            "BREAK"       : []
        }

        self.xmlProgram = None
        self.xml = ""
        self.instructionIndex = self.lineIndex = 0

        try:
            locale.setlocale(locale.LC_ALL, "en_CZ.UTF-8")
        except:
            locale.setlocale(locale.LC_ALL, "")

    """ Parse one instruction (opcode and its operands) """
    """ Returns result code """
    def parseInstruction(self, opcode, ops):

        # Returns success flag (True / False) and error message (or empty string)
        def checkName(s):
            specChars = "_-$&%*!?"
            if s == "":
                return False, "name is empty"
            if not s[0].isalpha() and s[0] not in specChars:
                return False, "illegal characters in name"
            for ch in s[1:]:
                if not ch.isalpha() and not ch.isdigit() and ch not in specChars:
                    return False, "illegal characters in name"
            return True, ""

        # Returns success flag (True / False) and error message (or empty string)
        def checkVarName(s):
            parts = s.split("@", 1)
            if len(parts) != 2:
                return False, "frame name is absent"
            frame, name = parts
            if frame not in ["GF", "LF", "TF"]:
                return False, "invalid frame name"
            return checkName(name)

        # Returns type string (or None on error) and value string (or error message)
        def checkConst(s):
            parts = s.split("@", 1)
            if len(parts) != 2:
                return None, "type is absent"
            type, value = parts
            if type == "nil":
                if value != "nil":
                    return None, "invalid value for nil type"
            elif type == "int":
                try:
                    base = 10
                    if value.lower().startswith("0o") or value.lower().startswith("-0o"):
                        base = 8
                    if value.lower().startswith("0x") or value.lower().startswith("-0x"):
                        base = 16
                    n = int(value, base)
                except ValueError:
                    return None, "invalid integer value"
            elif type == "bool":
                if value not in ["true", "false"]:
                    return None, "invalid value for bool type"
            elif type == "string":
                i = 1
                while i < len(value):
                    if value[i] == "\\":  # escape expression
                        n = -1
                        if i + 3 < len(value):
                            try:
                                n = int(value[i+1:i+4])
                            except ValueError:
                                pass
                        if n < 0:  # not enough digits or conversion error or negative value is specified
                            return False, "invalid escape expression in string"
                        i += 3
                    i += 1
            else:
                return None, "invalid type"
            return type, value

        # Returns success flag (True / False)
        def checkType(s):
            return s in ["int", "string", "bool"]

        # Check opcode and number of operands and add instruction to XML
        opTypes = self.Instructions.get(opcode.upper(), None)
        if opTypes == None:
            eprint(f"Invalid opcode \"{opcode}\" in line {self.lineIndex}!")
            return INVALID_OPCODE
        if len(ops) < len(opTypes):
            eprint(f"Not enough operands for opcode \"{opcode}\" in line {self.lineIndex}!")
            return SYNTAX_ERROR
        if len(ops) > len(opTypes):
            eprint(f"Too many operands for opcode \"{opcode}\" in line {self.lineIndex}!")
            return SYNTAX_ERROR
        xmlInstruction = ET.SubElement(self.xmlProgram, "instruction", {"order": str(self.instructionIndex), "opcode": opcode.upper()})

        # Check operands and add them to XML
        for i in range(len(opTypes)):
            operand = ops[i]
            type = opTypes[i]
            if type == self.OP_VAR:  # variable
                ok, msg = checkVarName(operand)
                if not ok:
                    eprint(f"Invalid variable operand \"{operand}\" ({msg}) for opcode \"{opcode}\" in line {self.lineIndex}!")
                    return SYNTAX_ERROR
                ET.SubElement(xmlInstruction, f"arg{i+1}", {"type": "var"}).text = operand
            elif type == self.OP_SYMB:  # variable or constant
                ok, msg = checkVarName(operand)
                if ok:
                    ET.SubElement(xmlInstruction, f"arg{i+1}", {"type": "var"}).text = operand
                else:
                    type, value = checkConst(operand)
                    if not type:
                        eprint(f"Invalid variable or constant operand \"{operand}\" ({msg} / {value}) for opcode \"{opcode}\" in line {self.lineIndex}!")
                        return SYNTAX_ERROR
                    ET.SubElement(xmlInstruction, f"arg{i+1}", {"type": type}).text = value
            elif type == self.OP_LABEL:  # label
                if not checkName(operand)[0]:
                    eprint(f"Illegal characters in label name \"{operand}\" for opcode \"{opcode}\" in line {self.lineIndex}!")
                    return SYNTAX_ERROR
                ET.SubElement(xmlInstruction, f"arg{i+1}", {"type": "label"}).text = operand
            elif type == self.OP_TYPE:  # type
                if not checkType(operand):
                    eprint(f"Invalid type name \"{operand}\" for opcode \"{opcode}\" in line {self.lineIndex}!")
                    return SYNTAX_ERROR
                ET.SubElement(xmlInstruction, f"arg{i+1}", {"type": "type"}).text = operand

        return SUCCESS

    """ Parse the specified open file """
    """ Returns result code """
    def parseFile(self, file_obj):

        self.xmlProgram = ET.Element("program", {"language": "IPPcode24"})
        self.xml = ""

        # Regular expression for line parsing (extract groups with opcode, 3 operands and extra operands)
        reParseLine = re.compile(r"(?:[ \t]*([^ \t\#]+)(?:[ \t]+([^ \t\#]+)(?:[ \t]+([^ \t\#]+)(?:[ \t]+([^ \t\#]+)((?:[ \t]+[^ \t\#]+)*))?)?)?)?[ \t]*(?:\#.*)?")

        # Read source code
        self.instructionIndex = self.lineIndex = 0
        try:
            for line in sys.stdin:
                self.lineIndex += 1
                line = line.rstrip("\n")
                matches = re.fullmatch(reParseLine, line)
                if matches == None:  # fail of regular expression
                    eprint(f"Regular expression error for line {self.lineIndex}!")
                    return INTERNAL_ERROR
                # Extract opcode and operands
                opcode, *ops = matches.groups()
                ops = list(filter(None, ops))  # filter out missing operands
                if opcode == None:  # empty or comment line
                    continue
                if self.instructionIndex == 0:  # the first non-empty line
                    if opcode.upper() != self.langId:
                        eprint(f"Invalid header (language ID) \"{opcode}\" in line {self.lineIndex}!")
                        return INVALID_HEADER
                else:
                    # Parse instruction (opcode and operands)
                    result = self.parseInstruction(opcode, ops)
                    if result != SUCCESS:
                        return result
                self.instructionIndex += 1
        except OSError:
            eprint("Standard input read error!")
            return INPUT_ERROR

        # Check number of processed lines and exit
        if self.instructionIndex == 0:
            eprint("File header (language ID) is absent!")
            return INVALID_HEADER

        # Format XML to string
        ET.indent(self.xmlProgram)
        self.xml = ET.tostring(self.xmlProgram, xml_declaration=True, encoding="unicode")

        return SUCCESS

    """ Returns formatted XML string """
    def getXml(self):

        return self.xml


""" MISC FUNCTIONS """

def printHelp():

    print("IPPcode24 Parser")
    print("Usage: python parse.py [--help]")
    print()
    print("Parser reads source code from standard input and writes to standard output.")
    print()
    print("Return codes:")
    print("0-9 : correct execution")
    print("10  : invalid parameters")
    print("11  : input error")
    print("12  : output error")
    print("21  : invalid or absent source header")
    print("22  : unknown or invalid opcode in source code")
    print("23  : other lexical or syntax error in source code")
    print("99  : internal error")


""" MAIN CODE """

if __name__ == "__main__":

    try:
        # Process options
        if len(sys.argv) == 2 and sys.argv[1] == "--help":
            printHelp()
            exit(SUCCESS)

        if len(sys.argv) > 1:
            eprint("Invalid command line options!")
            exit(10)

        # Parse source code
        parser = Parser()
        result = parser.parseFile(sys.stdin)

        if result == SUCCESS:
            # Output XML
            xml = parser.getXml()
            try:
                print(xml)
            except OSError:
                eprint("Standard output write error!")
                exit(OUTPUT_ERROR)
            eprint("Successfully parsed!")

    except Exception as e:
        eprint(f"Unexpected exception: {e} in line {e.__traceback__.tb_lineno}!")
        result = INTERNAL_ERROR

    exit(result)
