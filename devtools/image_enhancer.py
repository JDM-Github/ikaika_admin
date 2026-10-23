import argparse
from pathlib import Path
import shutil
import subprocess

try:
    from PIL import Image, ImageFilter, ImageEnhance
except ImportError:
    print("Missing dependency. Install it with:\n  py -m pip install pillow")
    raise SystemExit(1)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Enhance and upscale an image.")
    parser.add_argument("input", type=Path, help="Input image path (PNG/JPG/WEBP, etc.)")
    parser.add_argument("-o", "--output", type=Path, help="Output image path")
    parser.add_argument("--width", type=int, default=3840, help="Target canvas width (default: 3840)")
    parser.add_argument("--height", type=int, default=2160, help="Target canvas height (default: 2160)")
    parser.add_argument("--scale", type=float, help="Scale factor instead of fitting to the target canvas")
    parser.add_argument("--sharpen", type=float, default=1.15,
                        help="Sharpness amount (1.0 = unchanged; default: 1.15)")
    parser.add_argument("--no-sharpen", action="store_true", help="Disable sharpening")
    parser.add_argument("--denoise", action="store_true",
                        help="Apply a light smoothing filter before upscaling")
    parser.add_argument("--background", choices=["transparent", "white", "black"], default="transparent",
                        help="Background for non-transparent output; default preserves transparency")
    parser.add_argument("--ai", action="store_true",
                        help="Use an installed `realesrgan` command instead of standard processing")
    return parser.parse_args()


def output_path_for(source: Path, requested: Path | None) -> Path:
    if requested:
        return requested
    return source.with_name(f"{source.stem}_enhanced.png")


def run_ai_upscaler(source: Path, destination: Path) -> bool:
    """Try common Real-ESRGAN CLI usage; return False if the executable is absent."""
    executable = shutil.which("realesrgan")
    if not executable:
        print("Real-ESRGAN command not found. Install/configure it, or rerun without --ai.")
        return False

    # Many Real-ESRGAN builds use these flags. CLI options can vary by distribution.
    result = subprocess.run(
        [executable, "-i", str(source), "-o", str(destination), "-n", "realesrgan-x4plus"],
        check=False,
    )
    if result.returncode != 0:
        print("AI upscaler failed. Its command-line options may differ in your installation.")
        return False
    return destination.exists()


def main():
    args = parse_args()
    source = args.input.expanduser()

    if not source.is_file():
        print(f"Input file not found: {source}")
        return 2
    if args.width < 1 or args.height < 1:
        print("Width and height must be positive.")
        return 2
    if args.scale is not None and args.scale <= 0:
        print("--scale must be greater than zero.")
        return 2

    destination = output_path_for(source, args.output.expanduser() if args.output else None)
    destination.parent.mkdir(parents=True, exist_ok=True)

    if args.ai:
        if run_ai_upscaler(source, destination):
            print(f"AI-enhanced image saved to: {destination}")
            return 0
        return 1

    try:
        with Image.open(source) as opened:
            opened.load()
            has_alpha = "A" in opened.getbands() or "transparency" in opened.info
            image = opened.convert("RGBA" if has_alpha else "RGB")

        original_size = image.size

        # Optional light smoothing can help noisy/pixelated raster images, but
        # keep it off by default because it may soften small logo details.
        if args.denoise:
            if image.mode == "RGBA":
                alpha = image.getchannel("A")
                rgb = image.convert("RGB").filter(ImageFilter.MedianFilter(size=3))
                image = rgb.convert("RGBA")
                image.putalpha(alpha)
            else:
                image = image.filter(ImageFilter.MedianFilter(size=3))

        if args.scale is not None:
            target_w = max(1, round(image.width * args.scale))
            target_h = max(1, round(image.height * args.scale))
        else:
            ratio = min(args.width / image.width, args.height / image.height)
            target_w = max(1, round(image.width * ratio))
            target_h = max(1, round(image.height * ratio))

        # LANCZOS is a high-quality general-purpose resampling filter.
        image = image.resize((target_w, target_h), Image.Resampling.LANCZOS)

        if not args.no_sharpen and args.sharpen > 1.0:
            if image.mode == "RGBA":
                alpha = image.getchannel("A")
                rgb = ImageEnhance.Sharpness(image.convert("RGB")).enhance(args.sharpen)
                image = rgb.convert("RGBA")
                image.putalpha(alpha)
            else:
                image = ImageEnhance.Sharpness(image).enhance(args.sharpen)

        if args.background != "transparent" and image.mode == "RGBA":
            bg_color = (255, 255, 255) if args.background == "white" else (0, 0, 0)
            background = Image.new("RGB", image.size, bg_color)
            background.paste(image, mask=image.getchannel("A"))
            image = background

        # PNG output keeps quality lossless and supports transparency.
        image.save(destination, format="PNG", optimize=True)

        print("Image enhancement complete.")
        print(f"Input:   {source} ({original_size[0]} x {original_size[1]})")
        print(f"Output:  {destination} ({target_w} x {target_h})")
        print(f"Mode:    {image.mode}")
        print("Tip: For logos with clean shapes, SVG/vector artwork gives the crispest scaling.")
    except:
        pass