#!/usr/bin/env python

import argparse
import boinc_path_config
import json
import sys
import os
import tarfile
import xml.etree.cElementTree as ET
from Boinc.create_work import add_create_work_args, read_create_work_args, create_work, projdir, dir_hier_path
from functools import partial
from os.path import join, split, exists, basename, dirname, getsize
from subprocess import check_output, CalledProcessError, STDOUT
from xml.dom import minidom
from inspect import currentframe
from textwrap import dedent
from uuid import uuid4 as uuid
from tempfile import mkdtemp
from itertools import groupby


def boinc2docker_create_work(image,
                             command=None,
                             input_files=None,
                             appname='boinc2docker',
                             entrypoint=None,
                             prerun=None,
                             postrun=None,
                             verbose=True,
                             native_unzip=False,
                             memory=None,
                             disable_automatic_checkpoints=True,
                             progress_file=None,
                             vbox_job_xml=None,
                             create_work_args=None,
                             force_reimport=False):
    """

    Arguments:
        image - name of Docker image
        command - command (if any) to run as either string or list arguments
                  e.g ['echo','foo'] or 'echo foo'
        input_files - list of (open_name,contents,flags) for any extra files for this job
                      e.g. [('shared/foo','bar',['gzip','nodelete'])]
        appname - appname for which to submit job
        entrypoint - override default entrypoint
        prerun/postrun - command to run in the boinc_app script before/after the docker run
        verbose - print extra info
        native_unzip - lets the BOINC client do the unzipping of .tar.gz files into .tar files. otherwise
                       we do it by hand inside the VM. native_unzip=False is a workaround 
                       for https://github.com/BOINC/boinc/issues/1572. if you've tested a specific job is not 
                       affected by this bug, you can set native_unzip=True since its faster. otherwise
                       native_unzip=False is safer and is the default.
        vbox_job_xml - list of extra options to pass in vbox_job.xml file. e.g. 
                       [{'fraction_done_filename': 'progress'}, 'disable_automatic_checkpoints']
        create_work_args - any extra bin/create_work arguments to pass to the job, e.g. {'target_nresults':1}
        force_reimport - reimport the image into BOINC even if the image header file is there
    """

    fmt = partial(lambda s,f: s.format(**dict(globals(),**f.f_locals)),f=currentframe())
    sh = lambda cmd: check_output(fmt(cmd),shell=True,stderr=STDOUT).strip()
    

    if prerun is None: prerun=""
    if postrun is None: postrun=""
    if command is None: command=""
    if create_work_args is None: create_work_args=dict()
    if ':' not in image: image+=':latest'

    if memory: 
        print('WARNING: --memory is deprecated and will be removed in a future version. Use --rsc_memory_bound instead.')
        create_work_args['rsc_memory_bound'] = memory*1e6


    need_extract = False

    # tmp dir only created on-demand to reduce disk access
    _tmpdir=[None]
    def tmpdir():
        if _tmpdir[0] is None: 
            _tmpdir[0] = mkdtemp()
        return _tmpdir[0]

    try:

        #get entire image as a tar file
        try:
            image_id = get_image_id(image)
        except CalledProcessError as e:
            if 'No such image' in e.output:
                if verbose: print fmt("Pulling '{image}'...")
                sh('docker pull {image}')
                image_id = get_image_id(image)
            else:
                raise
        image_filename_tar = fmt("image_{image_id}.tar")
        image_filename = image_filename_tar + (".manual.gz" if not native_unzip else "")
        image_path = dir_hier_path(image_filename)
        image_path_tar = join(dirname(image_path),image_filename_tar)
        
        # configure memory/disk bounds
        image_size = get_image_size(image)
        create_work_args['rsc_memory_bound'] = int(memory_check(image_size, create_work_args.get('rsc_memory_bound'), verbose))
        create_work_args['rsc_disk_bound'] = int(disk_check(image_size, create_work_args.get('rsc_disk_bound'), verbose))

        if not force_reimport and exists(image_path):
            if verbose: print fmt("Image already imported into BOINC. Reading existing info...")
            manifest = get_manifest(image_path)
        else:
            if verbose: print fmt("Exporting '{image}' to tar file...")
            need_extract = True
            sh("docker save {image} | tar xf - -C %s"%tmpdir())
            manifest = json.load(open(join(tmpdir(),'manifest.json')))

        #start with any extra custom input files
        if input_files is None: input_files=[]
        else: 
            input_files = [(open_name,(basename(open_name),contents),flags) 
                           for open_name,contents,flags in input_files]


        #vbox_job.xml
        if vbox_job_xml is None: vbox_job_xml = []
        if disable_automatic_checkpoints: vbox_job_xml.append('disable_automatic_checkpoints')
        vbox_job_xml.append({'memory_size_mb':int(create_work_args['rsc_memory_bound']/1e6)})

        extra_opts = '\n'.join([' '*4+('<{0}>{1}</{0}>'.format(*i.items()[0]) if isinstance(i,dict) else '<%s/>'%i)
                                for i in vbox_job_xml])

        vbox_job_xml_contents = fmt(dedent("""
        <vbox_job>

            <os_name>Linux26_64</os_name>
            <enable_isocontextualization>1</enable_isocontextualization>
            <enable_shared_directory/>
            <enable_network/>
            <completion_trigger_file>completion_trigger_file</completion_trigger_file>

        {extra_opts}

        </vbox_job>
        """))

        input_files.append(("vbox_job.xml",("vbox_job.xml",vbox_job_xml_contents),[]))


        #generate boinc_app script
        if isinstance(command,str): command=command.split()
        command = ' '.join([escape_string(c) for c in command])
        entrypoint = '--entrypoint '+entrypoint if entrypoint else ''
        script = fmt(dedent("""
        #!/bin/sh
        set -e 

        echo "Importing Docker image from BOINC..."
        mkdir -p /tmp/image/combined
        for f in /root/shared/image/*.tar.manual.gz; do [ -e $f ] && gunzip -c $f > /tmp/image/$(basename $f .manual.gz); done
        cat $(for f in /root/shared/image/*.tar /tmp/image/*.tar; do [ -e $f ] && echo $f; done) | tar xi -C /tmp/image/combined
        rm  /tmp/image/*.tar
        tar cf - -C /tmp/image/combined . | docker load
        rm -rf /tmp/image

        echo "Prerun diagnostics..."
        docker images
        docker ps -a
        du -sh /var/lib/docker
        free -m

        echo "Prerun commands..."
        {prerun}

        echo "Running... "
        docker run --rm -v /root/shared:/root/shared {entrypoint} {image} {command}

        echo "Postrun commands..."
        {postrun}
        """))
        input_files.append(('shared/boinc_app',('boinc_app',script),[]))

        layer_flags = ['sticky','no_delete']
        if native_unzip: layer_flags += ['gzip']

        #extract layers to individual tar files, directly into download dir
        for layer in manifest[0]['Layers']:
            layer_id = split(layer)[0]
            layer_filename_tar = fmt("layer_{layer_id}.tar")
            layer_filename = layer_filename_tar + (".manual.gz" if not native_unzip else "")
            layer_path = dir_hier_path(layer_filename)
            layer_path_tar = join(dirname(layer_path),layer_filename_tar)
            input_files.append((fmt("shared/image/{layer_filename}"), layer_filename, layer_flags))
            if force_reimport or (need_extract and not exists(layer_path)): 
                if verbose: print fmt("Creating input file for layer %s..."%layer_id[:12])
                sh("tar cvf {layer_path_tar} -C %s {layer_id}"%tmpdir())
                if native_unzip:
                    sh("gzip -nfk {layer_path_tar}")
                else:
                    sh("gzip -nfS .manual.gz {layer_path_tar}")


        #extract remaining image info to individual tar file, directly into download dir
        input_files.append((fmt("shared/image/{image_filename}"), image_filename, layer_flags))
        if force_reimport or need_extract: 
            if verbose: print fmt("Creating input file for image %s..."%image_id[:12])
            sh("tar cvf {image_path_tar} -C %s {image_id}.json manifest.json repositories"%tmpdir())
            if native_unzip:
                sh("gzip -nfk {image_path_tar}")
            else:
                sh("gzip -nfS .manual.gz {image_path_tar}")

        #generate input template
        if verbose: print fmt("Creating input template for job...")
        root = ET.Element("input_template")
        for i,(open_name,_,flags) in enumerate(input_files):
            fileinfo = ET.SubElement(root, "file_info")
            ET.SubElement(fileinfo, "number").text = str(i)
            for flag in flags: ET.SubElement(fileinfo, flag)
        workunit = ET.SubElement(root, "workunit")
        for i,(open_name,_,flags) in enumerate(input_files):
            fileref = ET.SubElement(workunit, "file_ref")
            ET.SubElement(fileref, "file_number").text = str(i)
            ET.SubElement(fileref, "open_name").text = open_name
            ET.SubElement(fileref, "copy_file")
        template_file = join(tmpdir(),'boinc2docker_in_'+uuid().hex)
        open(template_file,'w').write(minidom.parseString(ET.tostring(root, 'utf-8')).toprettyxml(indent=" "*4))

        create_work_args['wu_template'] = template_file
        return create_work(appname, create_work_args, [f for _,f,_ in input_files]).strip()

    except KeyboardInterrupt:
        print("Cleaning up temporary files...")
    except CalledProcessError as e:
        print e.output.strip()
    finally:
        # cleanup
        try:
            if _tmpdir[0] is not None:
                sh("rm -rf %s"%tmpdir())
        except:
            pass


def sh(cmd):
    return check_output(cmd,shell=True,stderr=STDOUT).strip()


# some quantities memoized here since they require disk access
# and under high server load can be quite prohibitive

# http://code.activestate.com/recipes/578231-probably-the-fastest-memoization-decorator-in-the-/
def memoize(f):
    """ Memoization decorator for a function taking one or more arguments. """
    class memodict(dict):
        def __getitem__(self, *key):
            return dict.__getitem__(self, key)
        def __missing__(self, key):
            ret = self[key] = f(*key)
            return ret
    return memodict().__getitem__

@memoize
def get_image_id(image): 
    return sh('docker inspect --format "{{ .Id }}" '+image).strip().split(':')[1]

@memoize
def get_memory(image,memory,verbose):
    return int(memory_check(int(sh('docker inspect --format "{{ .Size }}" '+image))/1e6, memory, verbose))

@memoize
def get_manifest(image_path):
    return json.load(tarfile.open(image_path).extractfile('manifest.json'))

@memoize
def get_image_size(image):
    """
    Get the size of Docker image in bytes  
    """
    output = check_output("docker images --format '{{ .Size }}' "+image,shell=True,stderr=STDOUT).splitlines()
    if len(output)==0: 
        raise Exception("Trying to get size of unknown image '%s'"%image)
    elif len(output)>1:
        raise Exception("Trying to get size of ambiguous image name '%s'"%image)
    val, units = ["".join(x) for _, x in groupby(output[0], key=str.isalpha)]
    return float(val)*10**({'B':0,'KB':3,'MB':6,'GB':9}[units])


def memory_check(imagesize, memory, verbose=False):
    """
    Check we've got enough memory to `docker load` the image (and possibly unzip it)
    """
    # note: this should shrink to 1 (maybe 2) times imagesize once we have the vm_cache disk
    need = 4*imagesize + 500e6

    if memory is None:
        if verbose: print("Automatically setting memory allocation for job to %iMB."%int(need/1e6))
        return need
    elif memory<need: 
        if verbose: print("Warning: you allocated %iMB of memory for this job which is less than the prediceted minumum needed of %iMB; job may fail."%(int(memory/1e6),int(need/1e6)))
        return memory
    else:
        return memory

def disk_check(imagesize, disk, verbose=False):
    """
    Check we've got enough disk space to house the docker image input files
    """
    need = imagesize + 100e6

    if disk is None:
        if verbose: print("Automatically setting disk allocation for job to %iMB."%int(need/1e6))
        return need
    elif disk<need: 
        if verbose: print("Warning: you allocated %iMB of disk space for this job which is less than the prediceted minumum needed of %iMB; job may fail."%(int(disk/1e6),int(need/1e6)))
        return disk
    else:
        return disk


def escape_string(s):
    """
    Returns string with appropriate characters escaped so that it can be
    passed as a shell argument.
    """
    return check_output(["bash","-c",'printf "%q" "$@"','_', s])


if __name__=='__main__':

    parser = argparse.ArgumentParser(prog='boinc2docker_create_work')

    #docker args
    parser.add_argument('IMAGE', help='Docker image to run')
    parser.add_argument('COMMAND', nargs=argparse.REMAINDER, metavar='COMMAND', help='command to run')
    parser.add_argument('--entrypoint', help='Overwrite the default ENTRYPOINT of the image')

    #BOINC args
    parser.add_argument('--appname', default='boinc2docker', help='appname (default: boinc2docker)')
    parser.add_argument('--native_unzip', action='store_true', help="Let the BOINC client unzip image files (Warning: may cause job to fail, pending BOINC client bug fix)")
    parser.add_argument('--memory', type=int, help='deprecated: use --rsc_memory_bound instead.')
    parser.add_argument('--rsc_memory_bound', type=int, metavar='n', help='memory in bytes needed by this job (default: minimum needed to load Docker image)')
    parser.add_argument('--rsc_disk_bound', type=int, metavar='n', help='disk space in bytes needed by this job (default: minimum needed to load Docker image)')
    add_create_work_args(parser,exclude=['wu_template','rsc_memory_bound','rsc_disk_bound'])

    #other args
    parser.add_argument('--quiet', action="store_true", help="Don't print alot of messages.")
    parser.add_argument('--force_reimport', action="store_true", help="Force reimporting the image from Docker (might fix a corrupt previous import).")


    args = parser.parse_args()

    wu = boinc2docker_create_work(image=args.IMAGE, 
                                  command=args.COMMAND, 
                                  appname=args.appname,
                                  entrypoint=args.entrypoint,
                                  native_unzip=args.native_unzip,
                                  memory=args.memory,
                                  create_work_args=read_create_work_args(args),
                                  verbose=(not args.quiet),
                                  force_reimport=args.force_reimport)
    if wu is not None: print wu

