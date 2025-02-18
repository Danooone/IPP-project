# IPPcode24 Parser Documentation

## Basic Information

- **Name and Surname**: Zverev Daniil
- **xlogin** : xzvere00
### Overview

The IPPcode24 parser is structured into several key sections:

- **Constants**: Defines exit codes and control flags.
- **Utility Functions**: Includes a function for error messaging.
- **Parser Class**: Central to parsing logic, encapsulating methods for instruction parsing and XML generation.
- **Main Execution Logic**: Handles command-line arguments, invokes the parser, and manages error and success states.

### Constants

The script defines several constants used for exit codes to signal the script's execution outcome:

- `SUCCESS (0)`: Indicates successful execution.
- `PARAMETER_ERROR (10)`: Wrong command-line arguments.
- `INPUT_ERROR (11)`: Error reading input.
- `OUTPUT_ERROR (12)`: Error writing output.
- `INVALID_HEADER (21)`: Incorrect or missing program header.
- `INVALID_OPCODE (22)`: Unknown or invalid operation code encountered.
- `SYNTAX_ERROR (23)`: Other lexical or syntax errors in the source code.
- `INTERNAL_ERROR (99)`: Internal script error, such as exceptions not explicitly caught by other error handling.

The `stderrOutput` flag determines if messages should be printed to standard error, allowing for conditional error messaging.

### Utility Functions

#### eprint(*elems)

Prints given messages to standard error, respecting the `stderrOutput` flag.

- **Parameters**: `*elems` - Any number of arguments to be printed.
- **Returns**: None.

### Parser Class

The `Parser` class encapsulates the logic required to parse IPPcode24 source code into XML.

#### __init__(self)

Initializes parser instance variables, including the language ID, operation types, and an instructions dictionary mapping opcodes to their operand types. Sets up locale for consistent string processing.

#### parseInstruction(self, opcode, ops)

Parses a single instruction, verifying its opcode and operands, and adds it to the XML document.

- **Parameters**:
  - `opcode`: The operation code of the instruction.
  - `ops`: A list of operands for the instruction.
- **Returns**: Exit code indicating success or specific error.

#### parseFile(self, file_obj)

Parses the IPPcode24 source code from an open file object, constructing an XML document representing the program.

- **Parameters**:
  - `file_obj`: The file object for the source code.
- **Returns**: Exit code indicating success or specific error.

#### getXml(self)

Returns the XML string representation of the parsed IPPcode24 program.

- **Returns**: String containing the XML document.


### Design Philosophy
The design of the parser is based on a modular structure, which allows for a clear separation between the logic for reading and analyzing code and the logic for creating XML representation. This approach enhances the testability and extensibility of the code. 
### Internal Representation
Internally, the parser maintains information about instructions and their operands in the form of a dictionary, where keys represent operation codes and values are lists of expected operand types. This structure facilitates the validation and processing of individual instructions. The XML document is constructed incrementally by adding elements during the analysis of each instruction, ensuring that the resulting XML document accurately matches the structure and content of the source code.