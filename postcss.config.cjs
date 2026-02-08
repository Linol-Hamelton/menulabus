module.exports = {
  plugins: [
    require("autoprefixer"),
    require("cssnano")({
      preset: [
        "default",
        {
          cssDeclarationSorter: false,
          mergeLonghand: false,
        },
      ],
    }),
  ],
};
