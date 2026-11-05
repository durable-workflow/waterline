"""Source-only mock controls: no actual collector, image or route cache runs."""
import copy
import json
import pathlib
import subprocess
import sys
import tempfile
import time
import unittest
from unittest import mock

sys.path.insert(0,str(pathlib.Path(__file__).parent))
import evidence as e
import runner as r


def spec():
    return {'source':e.SOURCE,'platform':'linux/amd64','image_id':'sha256:'+'a'*64,
            'image_config_sha256':'b'*64,'browser_image_id':'sha256:'+'c'*64,
            'browser_config_sha256':'d'*64,'vendor_preparation_receipt_sha256':'e'*64,'packet_commit':'f'*40,'packet_tree':'0'*40}


class RunnerTests(unittest.TestCase):
    def test_inherited_browser_controls_are_git_bound_without_copied_bodies(self):
        root=pathlib.Path(__file__).resolve().parents[3]
        packet=pathlib.Path(__file__).resolve().parent
        import hashlib
        driver=(packet/'browser-driver.mjs').read_text()
        for name in ('browser','auto'):
            original=(root/'scripts/ci'/('request-budget-'+name+'.mjs')).read_bytes()
            self.assertIn(hashlib.sha256(original).hexdigest(),driver)
            self.assertFalse((packet/('budget-'+name+'.mjs')).exists())
        self.assertIn('Unapproved module import',driver)

    def test_execution_hold_precedes_every_side_effect(self):
        with mock.patch('runner.Session',side_effect=AssertionError('A role was created')):
            self.assertRaisesRegex(e.Refusal, 'HELD', r.execute)
            self.assertRaisesRegex(e.Refusal, 'HELD', r.execute_reviewed, spec(), '/unused-repo', '/unused-receipt', '/unused-output', {})
        with mock.patch.dict('os.environ',{'EXECUTION_ENABLED':'true','WATERLINE_SOURCE_SMOKE_EVIDENCE_PROPOSAL':'1'}):
            with mock.patch('runner.command',side_effect=AssertionError('A command ran')), mock.patch.object(sys,'argv',['runner']):
                self.assertRaisesRegex(e.Refusal, 'HELD', r.main)

    def test_cli_hold_precedes_mutable_helper_imports(self):
        packet=pathlib.Path(__file__).resolve().parent
        with tempfile.TemporaryDirectory() as directory:
            root=pathlib.Path(directory)
            marker=root/'unexpected-import'
            for name in ('evidence.py','packet_snapshot.py'):
                (root/name).write_text('from pathlib import Path;Path('+repr(str(marker))+').touch()')
            for name in ('runner.py','bootstrap.py','cleanup.py'):
                (root/name).write_bytes((packet/name).read_bytes())
                result=subprocess.run([sys.executable,'-I',str(root/name)],capture_output=True,timeout=3)
                self.assertNotEqual(result.returncode,0)
                self.assertIn(b'HELD',result.stderr)
                self.assertFalse(marker.exists())

    def test_wrong_source_platform_alias_and_missing_provenance_refuse(self):
        r.validate_spec(spec())
        for key,value in [('source','f'*40),('platform','linux/386'),('image_id','waterline:latest'),
                          ('image_config_sha256',''),('vendor_preparation_receipt_sha256','')]:
            bad=spec();bad[key]=value
            with self.subTest(key=key):
                self.assertRaises(e.Refusal, r.validate_spec, bad)

    def test_role_plan_preserves_isolation_and_boundaries(self):
        with tempfile.TemporaryDirectory() as directory:
            root=pathlib.Path(directory).resolve();inputs=root/'input';inputs.mkdir();out=root/'output';out.mkdir()
            s=r.Session(spec(),inputs,out);calls=[]
            s.bindings={s.name(role):{'Id':str(index)*64} for index,role in enumerate(('network','proxy'),1)}
            s.create_container=lambda role,options,image,arguments:calls.append(tuple(options)) or 'a'*64
            s.docker=mock.Mock(return_value=b'')
            s.check_bound=mock.Mock(return_value={'State':{'Running':True}})
            s.verify_container=mock.Mock()
            for role,_,args in [('fixture','php',[]),('app','sleep',[]),('proxy','php',[]),('browser','sleep',[])]:
                s.role(role,'sleep',['100'],browser=role=='browser')
            self.assertEqual(len(calls),4)
            for call in calls:
                for option in ('--init','--read-only','--user','--cpus','--memory','--memory-swap','--pids-limit','--cap-drop','--security-opt'):
                    self.assertIn(option,call)
                self.assertIn('--pull=never',call)
                self.assertIn('82:82',call);self.assertIn('ALL',call);self.assertIn('no-new-privileges',call)
                self.assertNotIn('-p',call);self.assertNotIn('--privileged',call);self.assertNotIn('host',call)
                for argument in call:
                    self.assertFalse(argument.startswith('type=bind,'))
                    self.assertNotIn('docker.sock',argument)
                self.assertIn('type=volume,src='+s.name('inputs')+',dst=/inputs,readonly',call)
            app=calls[1]
            self.assertFalse(any('dst=/state' in a for a in app))
            self.assertLess(s.active,s.deadline)
            self.assertLessEqual(s.deadline-s.started_at,1200)

    def test_image_source_platform_and_config_mismatch_refuse(self):
        with tempfile.TemporaryDirectory() as directory:
            root=pathlib.Path(directory).resolve();(root/'i').mkdir();(root/'e').mkdir();s=r.Session(spec(),root/'i',root/'e')
            config={'Labels':{'org.opencontainers.image.revision':e.SOURCE,'dev.durable-workflow.release.tag':'2.15.1'}}
            import hashlib
            digest=hashlib.sha256(json.dumps(config,sort_keys=True,separators=(',',':')).encode()).hexdigest()
            observation={'Id':spec()['image_id'],'Os':'linux','Architecture':'amd64','Config':config}
            s.docker=lambda *a,**k:json.dumps([observation]).encode()
            s.verify_image(spec()['image_id'],digest,True)
            for key,value in [('Architecture','arm64'),('Id','sha256:'+'f'*64)]:
                old=observation[key];observation[key]=value
                self.assertRaises(e.Refusal, s.verify_image, spec()['image_id'], digest, True)
                observation[key]=old
            self.assertRaises(e.Refusal, s.verify_image, spec()['image_id'], '0' * 64, True)
            config['Labels']['org.opencontainers.image.revision']='f'*40
            changed=hashlib.sha256(json.dumps(config,sort_keys=True,separators=(',',':')).encode()).hexdigest()
            self.assertRaises(e.Refusal, s.verify_image, spec()['image_id'], changed, True)

    def test_interrupted_cleanup_cannot_be_accepted(self):
        with tempfile.TemporaryDirectory() as directory:
            root=pathlib.Path(directory).resolve();(root/'i').mkdir();(root/'e').mkdir();s=r.Session(spec(),root/'i',root/'e')
            s.ownership_admitted=True;s.attempted.add(s.name('fixture'))
            s.docker=mock.Mock(side_effect=e.Refusal('uncertain cleanup'))
            self.assertRaises(e.Refusal, s.cleanup)
            self.assertRaises(e.Refusal, e.cleanup_receipt, {'containers': ['remaining'], 'networks': [], 'volumes': [], 'processes': []})

    def test_clean_mock_requires_independent_empty_readbacks(self):
        with tempfile.TemporaryDirectory() as directory:
            root=pathlib.Path(directory).resolve();(root/'i').mkdir();(root/'e').mkdir();s=r.Session(spec(),root/'i',root/'e')
            s.ownership_admitted=True;s.attempted.add(s.name('fixture'))
            calls=[]
            def docker(*args,**kw):
                calls.append((args,kw));return b''
            s.docker=docker
            result=s.cleanup();e.cleanup_receipt(result)
            self.assertEqual(len(calls),9)
            self.assertTrue(all(kw.get('cleanup') is True for _,kw in calls))
            self.assertEqual(sum('label='+r.LABEL+'='+s.token in args for args,_ in calls),6)

    def test_failed_cache_command_or_api_phase_cannot_become_a_receipt(self):
        with tempfile.TemporaryDirectory() as directory:
            root=pathlib.Path(directory).resolve();(root/'i').mkdir();(root/'e').mkdir();s=r.Session(spec(),root/'i',root/'e')
            s.write_mode=lambda mode:None
            s.exec=mock.Mock(side_effect=e.Refusal('cache command failed'))
            self.assertRaises(e.Refusal, s.phase, 'cached')
            self.assertEqual(s.observed,[])
            s.collector=lambda mode,request=None:{'status':502,'body':{}}
            self.assertRaises(e.Refusal, s.api, 'clear-before')


if __name__=='__main__':unittest.main()
