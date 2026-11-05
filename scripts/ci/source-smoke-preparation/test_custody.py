"""The new source packet is an exact, bounded Git allowlist."""
import pathlib
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import contract as c
import custody


class CustodyTests(unittest.TestCase):
    def reader(self, members=None, mode='100644', tree='2' * 40, mutate=False):
        members = custody.MEMBERS if members is None else members
        values = {name: name.encode() for name in members}
        objects = {f'{number:040x}': raw for number, raw in enumerate(values.values(), 1)}
        listing = b''.join(f'{mode} blob {oid}\t{custody.PREFIX}{name}\0'.encode()
            for name, oid in zip(values, objects))
        def git(*args):
            if args[0] == 'rev-parse':
                return tree.encode()
            if args[0] == 'ls-tree':
                return listing
            if args[1] == '-s':
                return str(len(objects[args[2]])).encode()
            return b'x' * len(objects[args[2]]) if mutate else objects[args[2]]
        return git

    def test_exact_source_bytes_remain_private_after_snapshot(self):
        raw, manifest, manifest_hash = custody.snapshot(self.reader(), '1' * 40, '2' * 40)
        self.assertEqual(set(raw), custody.MEMBERS)
        self.assertEqual(raw['binder.py'], b'binder.py')
        self.assertEqual(manifest_hash, c.digest(c.canonical(manifest)))
        changed = custody.snapshot(self.reader(mutate=True), '1' * 40, '2' * 40)
        self.assertNotEqual(changed[2], manifest_hash)

    def test_missing_extra_nonregular_and_wrong_tree_refuse(self):
        for reader in [self.reader(members=custody.MEMBERS - {'binder.py'}),
                       self.reader(members=custody.MEMBERS | {'extra.py'}), self.reader(mode='120000'),
                       self.reader(mode='100755'), self.reader(tree='3' * 40)]:
            with self.subTest(reader=reader):
                self.assertRaises(c.Refusal, custody.snapshot, reader, '1' * 40, '2' * 40)


if __name__ == '__main__':
    unittest.main()
