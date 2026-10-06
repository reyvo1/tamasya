#!/usr/bin/env python3
import argparse
import json
import re
from pathlib import Path

# MySQL schema files in TAMASYA intentionally use both canonical backtick-quoted
# identifiers (PMS) and ordinary unquoted identifiers (HQ).  Authority
# verification must understand both forms; otherwise a valid schema can be
# imported successfully while the verifier derives an empty expected set.
IDENT = r'(?:`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)'
QUALIFIED_PREFIX = rf'(?:(?:{IDENT})\s*\.\s*)?'
TABLE_RE = re.compile(
    rf'^\s*CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+{QUALIFIED_PREFIX}(?P<table>{IDENT})(?=\s*\()',
    re.I | re.M,
)
TRIGGER_RE = re.compile(
    rf'^\s*CREATE\s+TRIGGER\s+{QUALIFIED_PREFIX}(?P<trigger>{IDENT})\s+'
    rf'(?P<timing>BEFORE|AFTER)\s+(?P<event>INSERT|UPDATE|DELETE)\s+ON\s+'
    rf'{QUALIFIED_PREFIX}(?P<table>{IDENT})(?=\s)',
    re.I | re.M,
)


def unquote_identifier(token: str) -> str:
    token = token.strip()
    if len(token) >= 2 and token[0] == '`' and token[-1] == '`':
        return token[1:-1].replace('``', '`')
    return token


def parse_schema_files(schema_paths):
    expected_tables = set()
    expected_triggers = {}
    parsed_files = []
    for raw in schema_paths:
        path = Path(raw)
        text = path.read_text(encoding='utf-8', errors='strict')
        tables = [unquote_identifier(m.group('table')) for m in TABLE_RE.finditer(text)]
        triggers = []
        for m in TRIGGER_RE.finditer(text):
            name = unquote_identifier(m.group('trigger'))
            row = {
                'timing': m.group('timing').upper(),
                'event': m.group('event').upper(),
                'table': unquote_identifier(m.group('table')),
            }
            if name in expected_triggers and expected_triggers[name] != row:
                raise ValueError(f'conflicting trigger definition for {name!r}')
            expected_triggers[name] = row
            triggers.append(name)
        expected_tables.update(tables)
        parsed_files.append({'path': str(path), 'tables': len(tables), 'triggers': len(triggers)})
    return expected_tables, expected_triggers, parsed_files


def load_actual_tables(path: Path):
    return {line.rstrip('\n\r') for line in path.read_text(encoding='utf-8').splitlines() if line.strip()}


def load_actual_triggers(path: Path):
    actual = {}
    for line in path.read_text(encoding='utf-8').splitlines():
        if not line.strip():
            continue
        parts = line.split('\t')
        if len(parts) != 4:
            raise ValueError(f'invalid trigger metadata row: {line!r}')
        name, timing, event, table = parts
        actual[name] = {'timing': timing.upper(), 'event': event.upper(), 'table': table}
    return actual


def verify(expected_tables, expected_triggers, actual_tables, actual_triggers, schema_files, parsed_files):
    parser_issues = []
    if not expected_tables:
        parser_issues.append('no CREATE TABLE statements were parsed from supplied schema files')

    missing_tables = sorted(expected_tables - actual_tables)
    extra_tables = sorted(actual_tables - expected_tables)
    missing_triggers = sorted(set(expected_triggers) - set(actual_triggers))
    extra_triggers = sorted(set(actual_triggers) - set(expected_triggers))
    signature_mismatches = []
    for name in sorted(set(expected_triggers) & set(actual_triggers)):
        if expected_triggers[name] != actual_triggers[name]:
            signature_mismatches.append({
                'trigger': name,
                'expected': expected_triggers[name],
                'actual': actual_triggers[name],
            })

    return {
        'success': not (parser_issues or missing_tables or extra_tables or missing_triggers or extra_triggers or signature_mismatches),
        'schemaFiles': list(schema_files),
        'parsedFiles': parsed_files,
        'expectedTables': len(expected_tables),
        'actualTables': len(actual_tables),
        'expectedTriggers': len(expected_triggers),
        'actualTriggers': len(actual_triggers),
        'parserIssues': parser_issues,
        'missingTables': missing_tables,
        'extraTables': extra_tables,
        'missingTriggers': missing_triggers,
        'extraTriggers': extra_triggers,
        'triggerSignatureMismatches': signature_mismatches,
    }


def main(argv=None):
    parser = argparse.ArgumentParser(description='Verify fresh schema objects using migration/DBA metadata authority.')
    parser.add_argument('--tables', required=True, help='TSV file containing TABLE_NAME rows from information_schema.TABLES')
    parser.add_argument('--triggers', required=True, help='TSV file containing TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, EVENT_OBJECT_TABLE')
    parser.add_argument('schemas', nargs='+')
    args = parser.parse_args(argv)

    try:
        expected_tables, expected_triggers, parsed_files = parse_schema_files(args.schemas)
        actual_tables = load_actual_tables(Path(args.tables))
        actual_triggers = load_actual_triggers(Path(args.triggers))
        result = verify(expected_tables, expected_triggers, actual_tables, actual_triggers, args.schemas, parsed_files)
    except (OSError, UnicodeError, ValueError) as exc:
        print(json.dumps({'success': False, 'parserIssues': [str(exc)]}, separators=(',', ':'), sort_keys=True))
        return 1

    print(json.dumps(result, separators=(',', ':'), sort_keys=True))
    if not result['success']:
        return 1
    print(f"PASS migration-authority schema verification: tables={len(expected_tables)} triggers={len(expected_triggers)}")
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
