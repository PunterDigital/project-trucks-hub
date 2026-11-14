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
        if (shard == 0) JsonHelper.SaveTileMapInfo(outDir, origin.X, origin.X + TileSize / zoom0, origin.Y, origin.Y + TileSize / zoom0, 0, maxZoom);

        var flags = RenderFlags.Prefabs | RenderFlags.Roads | RenderFlags.MapAreas | RenderFlags.FerryConnections
            | RenderFlags.CityNames | RenderFlags.MapOverlays;

        for (var z = 0; z <= maxZoom; z++)
        {
            var size = (int)Math.Pow(2, z);
            Fit(mapper, size * TileSize, out var pos, out var zoom);
            var done = 0;
            var renderer = new TsMapRenderer(mapper);

            for (var x = shard; x < size; x += shards)
            {
                for (var y = 0; y < size; y++)
                {
                    SaveTile(renderer, z, x, y, pos, zoom, outDir, flags);
                    done++;
                }
            }

            Console.WriteLine($"[{shard}/{shards}] zoom {z}: {done} tiles, {clock.Elapsed.TotalSeconds:F0} s");
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

    private static void SaveTile(TsMapRenderer renderer, int z, int x, int y, PointF origin, float zoom, string outDir, RenderFlags flags)
    {
        using var bitmap = new Bitmap(TileSize, TileSize);
        using var g = Graphics.FromImage(bitmap);
        var pos = new PointF(origin.X + TileSize / zoom * x, origin.Y + TileSize / zoom * y);
        renderer.Render(g, new Rectangle(0, 0, TileSize, TileSize), zoom, pos, new HubPalette(), flags & ~RenderFlags.TextOverlay);

        var dir = Path.Combine(outDir, "tiles", z.ToString(), x.ToString());
        Directory.CreateDirectory(dir);
        bitmap.Save(Path.Combine(dir, y + ".png"), ImageFormat.Png);
    }
}

/// <summary>Dark palette to sit under the Hub's UI; roads light so pins stand out.</summary>
internal sealed class HubPalette : MapPalette
{
    public HubPalette()
    {
        Background = new SolidBrush(Color.FromArgb(24, 27, 34));
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
