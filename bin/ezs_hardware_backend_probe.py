#!/usr/bin/env python3
"""One-shot verified CPU/CUDA execution probe; physical UUID required for GPU attribution."""
import json
import re
import uuid as uuidlib

_UUID = re.compile(r"^(?:GPU-)?([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$")


def canonical_nvidia_uuid(raw):
    """Normalize an observed 128-bit CUDA UUID, never a GPU index or display name."""
    if raw is None:
        return None
    if isinstance(raw, (bytes, bytearray)):
        if len(raw) != 16:
            return None
        return "GPU-" + str(uuidlib.UUID(bytes=bytes(raw)))
    if not isinstance(raw, str):
        octets = getattr(raw, "bytes", None)
        if isinstance(octets, (bytes, bytearray, list, tuple)):
            try:
                as_bytes = bytes(octets)
            except (TypeError, ValueError):
                return None
            if len(as_bytes) == 16:
                return "GPU-" + str(uuidlib.UUID(bytes=as_bytes))
        # torch._C._CUuuid implements __str__ with canonical 8-4-4-4-12 format.
        try:
            raw = str(raw)
        except Exception:
            return None
    matched = _UUID.fullmatch(raw)
    if matched is None:
        return None
    try:
        return "GPU-" + str(uuidlib.UUID(matched.group(1)))
    except ValueError:
        return None



def _opencl_driver():
    """Use the already-installed OpenCL ICD loader; no third-party Python packages."""
    import ctypes as C
    import os
    if os.name == 'nt':
        return C.WinDLL('OpenCL.dll')
    return C.CDLL('libOpenCL.so.1')


def _opencl_pci_proofs(loader):
    """Run an OpenCL kernel on each identified Intel GPU and return PCI IDs only.

    CL_DEVICE_ID_INTEL (0x4251) is the Intel PCI *device type* ID; PHP
    requires a unique physical match, so identical Intel cards cannot be
    attributed here. A platform name alone is never backend proof.
    """
    import ctypes as C
    cl_uint, cl_int, cl_ulong = C.c_uint, C.c_int, C.c_ulong
    handle, size_t = C.c_void_p, C.c_size_t
    ptr = C.POINTER

    def api(name, args, restype):
        f = getattr(loader, name)
        f.argtypes = args
        f.restype = restype
        return f

    get_platforms = api('clGetPlatformIDs', [cl_uint, ptr(handle), ptr(cl_uint)], cl_int)
    get_devices = api('clGetDeviceIDs', [handle, cl_ulong, cl_uint, ptr(handle), ptr(cl_uint)], cl_int)
    get_info = api('clGetDeviceInfo', [handle, cl_uint, size_t, handle, ptr(size_t)], cl_int)
    create_context = api('clCreateContext', [handle, cl_uint, ptr(handle), handle, handle, ptr(cl_int)], handle)
    create_queue = api('clCreateCommandQueue', [handle, handle, cl_ulong, ptr(cl_int)], handle)
    create_program = api('clCreateProgramWithSource', [handle, cl_uint, ptr(C.c_char_p), ptr(size_t), ptr(cl_int)], handle)
    build_program = api('clBuildProgram', [handle, cl_uint, ptr(handle), C.c_char_p, handle, handle], cl_int)
    create_kernel = api('clCreateKernel', [handle, C.c_char_p, ptr(cl_int)], handle)
    create_buffer = api('clCreateBuffer', [handle, cl_ulong, size_t, handle, ptr(cl_int)], handle)
    set_arg = api('clSetKernelArg', [handle, cl_uint, size_t, handle], cl_int)
    enqueue = api('clEnqueueNDRangeKernel', [handle, handle, cl_uint, ptr(size_t), ptr(size_t), ptr(size_t), cl_uint, handle, handle], cl_int)
    read_buffer = api('clEnqueueReadBuffer', [handle, handle, cl_uint, size_t, size_t, handle, cl_uint, handle, handle], cl_int)
    finish = api('clFinish', [handle], cl_int)
    release_buffer = api('clReleaseMemObject', [handle], cl_int)
    release_kernel = api('clReleaseKernel', [handle], cl_int)
    release_program = api('clReleaseProgram', [handle], cl_int)
    release_queue = api('clReleaseCommandQueue', [handle], cl_int)
    release_context = api('clReleaseContext', [handle], cl_int)

    def info_uint(device, param):
        value = cl_uint(0)
        rc = get_info(device, param, C.sizeof(value), C.byref(value), None)
        return value.value if rc == 0 else None

    def info_text(device, param):
        n = size_t(0)
        if get_info(device, param, 0, None, C.byref(n)) != 0 or not (0 < n.value < 65536):
            return ''
        buf = C.create_string_buffer(n.value)
        if get_info(device, param, n.value, buf, None) != 0:
            return ''
        return buf.value.decode('ascii', errors='replace')

    def calculate(device):
        resources = []
        try:
            err = cl_int(-1)
            handles = (handle * 1)(device)
            ctx = create_context(None, 1, handles, None, None, C.byref(err))
            if err.value or not ctx:
                return False
            resources.append((release_context, ctx))
            queue = create_queue(ctx, device, 0, C.byref(err))
            if err.value or not queue:
                return False
            resources.append((release_queue, queue))
            src = b'__kernel void ezs_probe(__global int *v) { v[get_global_id(0)] += 7; }'
            source = (C.c_char_p * 1)(src)
            program = create_program(ctx, 1, source, None, C.byref(err))
            if err.value or not program:
                return False
            resources.append((release_program, program))
            if build_program(program, 1, handles, None, None, None) != 0:
                return False
            kernel = create_kernel(program, b'ezs_probe', C.byref(err))
            if err.value or not kernel:
                return False
            resources.append((release_kernel, kernel))
            original = (cl_int * 4)(1, 2, 3, 4)
            # CL_MEM_READ_WRITE | CL_MEM_COPY_HOST_PTR
            buffer = create_buffer(ctx, 1 | (1 << 5), C.sizeof(original), C.cast(original, handle), C.byref(err))
            if err.value or not buffer:
                return False
            resources.append((release_buffer, buffer))
            arg = handle(buffer)
            if set_arg(kernel, 0, C.sizeof(arg), C.cast(C.byref(arg), handle)) != 0:
                return False
            global_size = (size_t * 1)(4)
            if enqueue(queue, kernel, 1, None, global_size, None, 0, None, None) != 0:
                return False
            result = (cl_int * 4)()
            if read_buffer(queue, buffer, 1, 0, C.sizeof(result), C.cast(result, handle), 0, None, None) != 0:
                return False
            if finish(queue) != 0:
                return False
            return tuple(result) == (8, 9, 10, 11)
        finally:
            for release, obj in reversed(resources):
                try:
                    release(obj)
                except Exception:
                    pass

    platforms_count = cl_uint(0)
    if get_platforms(0, None, C.byref(platforms_count)) != 0 or not (0 < platforms_count.value <= 64):
        return []
    platforms = (handle * platforms_count.value)()
    if get_platforms(platforms_count.value, platforms, None) != 0:
        return []
    proofs = []
    seen = set()
    ambiguous = set()
    for platform in platforms:
        count = cl_uint(0)
        # CL_DEVICE_TYPE_GPU = 1 << 2; CL_DEVICE_NOT_FOUND = -1.
        if get_devices(platform, 4, 0, None, C.byref(count)) != 0 or not (0 < count.value <= 64):
            continue
        devices = (handle * count.value)()
        if get_devices(platform, 4, count.value, devices, None) != 0:
            continue
        for device in devices:
            try:
                if info_uint(device, 0x1001) != 0x8086:  # CL_DEVICE_VENDOR_ID
                    continue
                if 'cl_intel_device_attribute_query' not in info_text(device, 0x1030).split():
                    continue
                pci_device = info_uint(device, 0x4251)  # CL_DEVICE_ID_INTEL
                if pci_device is None or not 0 < pci_device <= 0xffff:
                    continue
                if not calculate(device):
                    continue
                key = (0x8086, pci_device)
                if key in ambiguous:
                    continue
                if key in seen:
                    ambiguous.add(key)
                    proofs = [p for p in proofs if (p['pci_vendor_id'], p['pci_device_id']) != key]
                    continue
                seen.add(key)
                unified = info_uint(device, 0x1035)  # CL_DEVICE_HOST_UNIFIED_MEMORY
                proof = {'pci_vendor_id': 0x8086, 'pci_device_id': pci_device,
                         'backends': ['OpenCL']}
                if unified in (0, 1):
                    proof['host_unified_memory'] = (unified == 1)
                proofs.append(proof)
            except Exception:
                continue
    return proofs


def probe_opencl():
    try:
        return _opencl_pci_proofs(_opencl_driver())
    except (OSError, AttributeError, ImportError, ValueError, TypeError):
        return []


def probe():
    result = {"cpu": ["CPU"], "gpus": [], "opencl": probe_opencl()}
    try:
        import torch
    except (ImportError, OSError):
        return result
    try:
        a = torch.tensor([2.0, 3.0], device="cpu")
        if bool((a * a).sum().item() == 13.0):
            result["cpu"].append("PyTorch CPU")
    except (RuntimeError, ValueError, OSError):
        pass
    try:
        if not torch.cuda.is_available():
            return result
        count = torch.cuda.device_count()
    except (RuntimeError, AssertionError, OSError):
        return result
    for i in range(count):
        try:
            props = torch.cuda.get_device_properties(i)
            # Never associate a CUDA ordinal or a name with a physical adapter.
            nvidia_uuid = canonical_nvidia_uuid(getattr(props, "uuid", None))
            if nvidia_uuid is None:
                continue
            with torch.cuda.device(i):
                x = torch.tensor([2.0, 3.0], device=f"cuda:{i}")
                y = (x * x).sum().item()
                torch.cuda.synchronize(i)
            if y == 13.0:
                result["gpus"].append({"nvidia_uuid": nvidia_uuid, "backends": ["PyTorch CUDA"]})
        except (RuntimeError, AssertionError, ValueError, OSError):
            continue
    return result


if __name__ == "__main__":
    try:
        print(json.dumps(probe(), separators=(",", ":"), ensure_ascii=True))
    except Exception:
        print(json.dumps({"cpu": ["CPU"], "gpus": [], "opencl": []}))
