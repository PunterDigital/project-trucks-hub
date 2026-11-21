// Renders the ETS2 map into 256 px web tiles ({z}/{x}/{y}.png) plus TileMapInfo.json, the same
// layout and maths as ts-map's own "Generate tile map" (TsMap.Canvas/TsMapCanvas.cs), without its
// window. Usage: TileExporter <game dir> <output dir> <max zoom> [<shard> <shards>]
// GDI+ bitmaps (map overlays) can't be drawn from two threads, so generate.mjs runs several
// processes instead: shard i renders the tile columns where x % shards == i.
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Imaging;
using System.IO;
using TsMap;

internal static class Program
{
    private const int TileSize = 256;
    private const float MapPadding = 500; // ts-map's own padding around the map, in game metres

    private static int Main(string[] args)
    {
        if (args.Length < 3)
        {
            Console.Error.WriteLine("Usage: TileExporter <game dir> <output dir> <max zoom> [<shard> <shards>]");
            return 2;
        }

        var gameDir = args[0];
        var outDir = args[1];
        var maxZoom = int.Parse(args[2]);
        var shard = args.Length >= 5 ? int.Parse(args[3]) : 0;
        var shards = args.Length >= 5 ? int.Parse(args[4]) : 1;

        var clock = Stopwatch.StartNew();
        var mapper = new TsMapper(gameDir, new List<Mod>());
        mapper.Parse();
        Console.WriteLine($"Parsed the map in {clock.Elapsed.TotalSeconds:F0} s ({mapper.Cities.Count} cities)");

        // Level 0: one tile with the whole map, edge to edge on the longer axis.
        Fit(mapper, TileSize, out var origin, out var zoom0);
        // Cities.json: every city with its game x/z (ts-map's own export), for checking that pins
        // line up with the tiles and for labels later.
        Directory.CreateDirectory(outDir); // ts-map's export skips a missing folder silently
        if (shard == 0) mapper.ExportInfo(ExportFlags.CityList, outDir);
        if (shard == 0) JsonHelper.SaveTileMapInfo(outDir, origin.X, origin.X + TileSize / zoom0, origin.Y, origin.Y + TileSize / zoom0, 0, maxZoom);

        var flags = RenderFlags.Prefabs | RenderFlags.Roads | RenderFlags.MapAreas | RenderFlags.FerryConnections
            | RenderFlags.CityNames | RenderFlags.MapOverlays;

        for (var z = 0; z <= maxZoom; z++)
        {
            var size = (int)Math.Pow(2, z);
            Fit(mapper, size * TileSize, out var pos, out var zoom);
            var done = 0;
            var saved = 0;
            var renderer = new TsMapRenderer(mapper);

            for (var x = shard; x < size; x += shards)
            {
                for (var y = 0; y < size; y++)
                {
                    if (SaveTile(renderer, z, x, y, pos, zoom, outDir, flags))
                    {
                        saved++;
                    }
                    done++;
                }
            }

            Console.WriteLine($"[{shard}/{shards}] zoom {z}: {saved} of {done} tiles kept, {clock.Elapsed.TotalSeconds:F0} s");
        }

        return 0;
    }

    private static void Fit(TsMapper mapper, float target, out PointF pos, out float zoom)
    {
        var mapWidth = mapper.maxX - mapper.minX + MapPadding * 2;
        var mapHeight = mapper.maxZ - mapper.minZ + MapPadding * 2;
        if (mapWidth > mapHeight)
        {
            zoom = target / mapWidth;
            pos = new PointF(mapper.minX - MapPadding, mapper.minZ - MapPadding + -(target / zoom) / 2f + mapHeight / 2f);
        }
        else
        {
            zoom = target / mapHeight;
            pos = new PointF(mapper.minX - MapPadding + -(target / zoom) / 2f + mapWidth / 2f, mapper.minZ - MapPadding);
        }
    }

    /// <returns>false when the tile was blank and not written.</returns>
    private static bool SaveTile(TsMapRenderer renderer, int z, int x, int y, PointF origin, float zoom, string outDir, RenderFlags flags)
    {
        // Render with a margin and keep the middle: ts-map only draws what falls inside the
        // clip, so a city name across a tile edge would otherwise be cut off in both tiles.
        const int margin = 128;
        const int full = TileSize + 2 * margin;
        using var big = new Bitmap(full, full);
        using (var g = Graphics.FromImage(big))
        {
            var pos = new PointF(
                origin.X + TileSize / zoom * x - margin / zoom,
                origin.Y + TileSize / zoom * y - margin / zoom);
            renderer.Render(g, new Rectangle(0, 0, full, full), zoom, pos, new HubPalette(), flags & ~RenderFlags.TextOverlay);
        }

        using var tile = new Bitmap(TileSize, TileSize);
        using (var g = Graphics.FromImage(tile))
        {
            g.DrawImage(big, new Rectangle(0, 0, TileSize, TileSize), new Rectangle(margin, margin, TileSize, TileSize), GraphicsUnit.Pixel);
        }

        // Sea and the area around the map are plain background: no file. generate.mjs lists the
        // tiles that exist (TileIndex.json), and the map draws the background for the rest without
        // asking for them (#5207). Over half the tiles are blank, more at the deeper levels.
        if (IsBlank(tile))
        {
            return false;
        }

        var dir = Path.Combine(outDir, "tiles", z.ToString(), x.ToString());
        Directory.CreateDirectory(dir);
        tile.Save(Path.Combine(dir, y + ".png"), ImageFormat.Png);

        return true;
    }

    private static bool IsBlank(Bitmap tile)
    {
        var background = HubPalette.BackgroundColor.ToArgb();
        var data = tile.LockBits(new Rectangle(0, 0, tile.Width, tile.Height), ImageLockMode.ReadOnly, PixelFormat.Format32bppArgb);
        try
        {
            var row = new int[tile.Width];
            for (var y = 0; y < tile.Height; y++)
            {
                System.Runtime.InteropServices.Marshal.Copy(data.Scan0 + y * data.Stride, row, 0, tile.Width);
                foreach (var pixel in row)
                {
                    if (pixel != background)
                    {
                        return false;
                    }
                }
            }

            return true;
        }
        finally
        {
            tile.UnlockBits(data);
        }
    }
}

/// <summary>Dark palette to sit under the Hub's UI; roads light so pins stand out.</summary>
internal sealed class HubPalette : MapPalette
{
    /// <summary>The map page's own background (#181b22, `.hub-map` in pages/map/Index.vue).</summary>
    public static readonly Color BackgroundColor = Color.FromArgb(24, 27, 34);

    public HubPalette()
    {
        Background = new SolidBrush(BackgroundColor);
        Road = new SolidBrush(Color.FromArgb(150, 158, 175));
        PrefabRoad = new SolidBrush(Color.FromArgb(150, 158, 175));
        PrefabLight = new SolidBrush(Color.FromArgb(52, 58, 72));
        PrefabDark = new SolidBrush(Color.FromArgb(70, 77, 94));
        PrefabGreen = new SolidBrush(Color.FromArgb(38, 52, 44));
        CityName = new SolidBrush(Color.FromArgb(230, 232, 238));
        FerryLines = new SolidBrush(Color.FromArgb(90, 120, 170, 230));
        Error = Brushes.LightCoral;
    }
}
